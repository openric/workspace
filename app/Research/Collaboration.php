<?php

// OpenRiC Workspace - working together on research: discussions, comments on evidence, who is looking now, and
// comments on annotations, diary entries and collections (roadmap phase 19f, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's CollaborationService (createDiscussion, getDiscussions, addReply, updateDiscussion,
// deleteDiscussion, resolveDiscussion, pinDiscussion), CollaborationRealtimeService (joinProject, heartbeat,
// presence, postComment, resolveComment, commentsSince, poll) and research_comment. A discussion belongs to a research
// workspace or a research project; its members write and reply; its author, or who manages it, changes, pins, resolves
// or deletes it. Comments on the evidence of a project are on one of its collections, or an item of one, and are
// followed as they come by polling, as Heratio's are, with who else has the project open in the last 90 seconds.
// Changed: Heratio polls by the last comment's number; the Workspace's ids are not numbers in order, so it polls by
// time. A comment on an annotation, diary entry or collection is written by who may read it, and its owner is told.

namespace App\Research;

use App\Audit\AuditLog;
use App\Mail\Outbox;
use App\Mail\ResearchMail;
use App\Models\ResearchAnnotation;
use App\Models\ResearchCollection;
use App\Models\ResearchCollectionItem;
use App\Models\ResearchComment;
use App\Models\ResearchDiaryEntry;
use App\Models\ResearchDiscussion;
use App\Models\ResearchEvidenceComment;
use App\Models\ResearchProject;
use App\Models\ResearchProjectPresence;
use App\Models\ResearchReport;
use App\Models\ResearchReportSection;
use App\Models\ResearchWorkspace;
use App\Models\ResearchWorkspaceMember;
use App\Models\User;
use App\Support\Vocab;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Collaboration
{
    private const PRESENCE_SECONDS = 90;

    /** Heratio's palette for who is looking. */
    private const COLOURS = ['#4e79a7', '#f28e2b', '#e15759', '#76b7b2', '#59a14f', '#edc949', '#af7aa1', '#ff9da7', '#9c755f', '#bab0ab'];

    /** What a comment may be on (Heratio's research_comment.entity_type). */
    public const COMMENTABLE = ['research_annotations' => ResearchAnnotation::class, 'research_diary_entries' => ResearchDiaryEntry::class, 'research_collections' => ResearchCollection::class,
        'research_reports' => ResearchReport::class, 'research_report_sections' => ResearchReportSection::class];

    public function __construct(private Projects $projects, private ResearchWorkspaces $workspaces, private Outbox $outbox) {}

    // Discussions.

    /** How far an account may go in a discussion's home: 0 none, 1 read, 2 write, 3 manage. */
    public function level(User $user, ResearchProject|ResearchWorkspace $home): int
    {
        if ($home instanceof ResearchProject) {
            $member = $this->projects->membership($user, $home);

            return $member ? (Projects::LEVELS[$member->role->notation] >= Projects::LEVELS['editor'] ? 3 : 2) : min(1, $this->projects->level($user, $home));
        }
        $member = $this->workspaces->membership($user, $home);

        return $member ? (ResearchWorkspaces::LEVELS[$member->role->notation] >= ResearchWorkspaces::LEVELS['admin'] ? 3 : 2) : min(1, $this->workspaces->level($user, $home));
    }

    public function discussions(ResearchProject|ResearchWorkspace $home): Collection
    {
        return ResearchDiscussion::where($home instanceof ResearchProject ? 'project_id' : 'workspace_id', $home->id)->whereNull('parent_id')
            ->with('user', 'resolvedBy', 'replies.user')->orderByDesc('is_pinned')->orderByDesc('created_at')->get();
    }

    public function discuss(User $user, ResearchProject|ResearchWorkspace $home, array $data, ?ResearchDiscussion $parent = null): ResearchDiscussion
    {
        if ($this->level($user, $home) < 2) {
            throw new InvalidArgumentException(__('Only its members take part.'));
        }
        if (trim((string) ($data['content'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Write something.'));
        }
        if ($parent && ($parent->parent_id !== null || $parent->{$home instanceof ResearchProject ? 'project_id' : 'workspace_id'} !== $home->id)) {
            throw new InvalidArgumentException(__('Reply to a discussion of this place.'));
        }

        return DB::transaction(fn () => AuditLog::actingAs($user, function () use ($user, $home, $data, $parent) {
            $discussion = ResearchDiscussion::create(['institution_id' => $home->institution_id, $home instanceof ResearchProject ? 'project_id' : 'workspace_id' => $home->id,
                'parent_id' => $parent?->id, 'user_id' => $user->id, 'subject' => $parent ? null : (trim((string) ($data['subject'] ?? '')) ?: null), 'content' => trim($data['content'])]);
            if ($home instanceof ResearchProject) {
                $this->projects->log($home, $user, 'discuss', $parent ?? $discussion, ['reply' => $parent !== null]);
            }

            return $discussion;
        }));
    }

    /** Change, pin, resolve or delete: its author, or who manages the place. */
    public function discussionStep(User $user, ResearchDiscussion $discussion, string $step, array $data = []): void
    {
        $home = $discussion->project ?? $discussion->workspace;
        $manager = $this->level($user, $home) >= 3;
        if (! $manager && ($discussion->user_id !== $user->id || $step === 'pin')) {
            throw new InvalidArgumentException(__('Only its author or who manages it may do that.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => match ($step) {
            'update' => trim((string) ($data['content'] ?? '')) !== '' ? $discussion->update(['content' => trim($data['content']),
                'subject' => $discussion->parent_id ? null : (trim((string) ($data['subject'] ?? '')) ?: null)]) : throw new InvalidArgumentException(__('Write something.')),
            'pin' => $discussion->update(['is_pinned' => ! $discussion->is_pinned]),
            'resolve' => $discussion->update(['is_resolved' => ! $discussion->is_resolved, 'resolved_by_id' => $discussion->is_resolved ? null : $user->id,
                'resolved_at' => $discussion->is_resolved ? null : now()]),
            'delete' => $discussion->delete(),
        }));
    }

    // Comments on the evidence of a project, and who is looking (CollaborationRealtimeService).

    public function comment(User $user, ResearchProject $project, array $data): ResearchEvidenceComment
    {
        if ($this->level($user, $project) < 2) {
            throw new InvalidArgumentException(__('Only its members take part.'));
        }
        if (trim((string) ($data['body'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Write something.'));
        }
        $collection = ($data['collection_id'] ?? null) ? ResearchCollection::where(['id' => $data['collection_id'], 'project_id' => $project->id])->first()
            ?? throw new InvalidArgumentException(__('Choose a collection of this project.')) : null;
        $item = ($data['item_id'] ?? null) ? ResearchCollectionItem::where('id', $data['item_id'])->where('collection_id', $collection?->id)->first()
            ?? throw new InvalidArgumentException(__('Choose an item of that collection.')) : null;
        $parent = ($data['parent_id'] ?? null) ? $project->evidenceComments()->where('id', $data['parent_id'])->first() ?? throw new InvalidArgumentException(__('Reply to a comment of this project.')) : null;

        return DB::transaction(fn () => AuditLog::actingAs($user, function () use ($user, $project, $data, $collection, $item, $parent) {
            $comment = $project->evidenceComments()->create(['collection_id' => $collection?->id ?? $parent?->collection_id, 'item_id' => $item?->id ?? $parent?->item_id,
                'parent_id' => $parent?->id, 'author_id' => $user->id, 'body' => trim($data['body'])]);
            $this->projects->log($project, $user, 'discuss', $collection, ['evidence' => true]);

            return $comment;
        }));
    }

    public function resolveComment(User $user, ResearchEvidenceComment $comment): void
    {
        if ($comment->author_id !== $user->id && $this->level($user, $comment->project) < 3) {
            throw new InvalidArgumentException(__('Only its author or who manages it may do that.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $comment->update(['is_resolved' => ! $comment->is_resolved,
            'resolved_by_id' => $comment->is_resolved ? null : $user->id, 'resolved_at' => $comment->is_resolved ? null : now()])));
    }

    /** Heratio's joinProject and heartbeat: one is looking at the project, at a part of it. */
    public function here(User $user, ResearchProject $project, ?string $target = null): string
    {
        if ($this->projects->level($user, $project) < 1) {
            throw new InvalidArgumentException(__('That is not yours.'));
        }
        $colour = self::COLOURS[hexdec(substr(hash('crc32b', $user->id), 0, 4)) % count(self::COLOURS)];
        ResearchProjectPresence::updateOrCreate(['project_id' => $project->id, 'user_id' => $user->id],
            ['cursor_target' => $target !== null ? mb_substr($target, 0, 200) : null, 'user_color' => $colour, 'last_seen_at' => now()]);

        return $colour;
    }

    /**
     * Heratio's poll: who has looked in the last 90 seconds, and the comments since a moment.
     *
     * @return array{presence: list<array>, comments: list<array>, cursor: string, server_ts: string}
     */
    public function poll(User $user, ResearchProject $project, ?string $since = null): array
    {
        $this->here($user, $project);
        $since = $since ? rescue(fn () => CarbonImmutable::parse($since), null, false) : null;
        $comments = $project->evidenceComments()->with('author')->when($since, fn ($q) => $q->where('created_at', '>', $since))->limit(200)->get();

        return ['presence' => ResearchProjectPresence::where('project_id', $project->id)->where('last_seen_at', '>=', now()->subSeconds(self::PRESENCE_SECONDS))
            ->with('user')->orderByDesc('last_seen_at')->get()->map(fn ($p) => ['name' => $p->user->name, 'colour' => $p->user_color, 'target' => $p->cursor_target,
                'seen' => $p->last_seen_at->toIso8601String()])->values()->all(),
            'comments' => $comments->map(fn ($c) => ['id' => $c->id, 'author' => $c->author?->name, 'body' => $c->body, 'resolved' => $c->is_resolved, 'collection_id' => $c->collection_id,
                'item_id' => $c->item_id, 'parent_id' => $c->parent_id, 'created' => $c->created_at->toIso8601String()])->values()->all(),
            'cursor' => ($comments->max('created_at') ?? $since ?? now())->toIso8601String(), 'server_ts' => now()->toIso8601String()];
    }

    // Comments on annotations, diary entries and collections (research_comment).

    /** Whether an account may read the thing: an annotation it may read, a diary entry it may read, a collection it owns or works on through a project. */
    public function mayRead(User $user, Model $subject): bool
    {
        return match (true) {
            $subject instanceof ResearchAnnotation => app(Annotations::class)->readable($user)->whereKey($subject->id)->exists(),
            $subject instanceof ResearchDiaryEntry => $subject->user_id === $user->id || ! $subject->is_private && $this->colleagues($user)->contains($subject->user_id),
            $subject instanceof ResearchCollection => $subject->user_id === $user->id || $subject->project && $this->projects->level($user, $subject->project) >= 1,
            $subject instanceof ResearchReport => app(Reports::class)->mayRead($user, $subject),
            $subject instanceof ResearchReportSection => app(Reports::class)->mayRead($user, $subject->report),
            default => false,
        } && $this->owner($subject)?->institution_id === $user->institution_id;
    }

    public function comments(Model $subject): Collection
    {
        return ResearchComment::where(['subject_table' => $subject->getTable(), 'subject_id' => $subject->getKey()])->whereNull('parent_id')->with('user')->orderBy('created_at')->get()
            ->each(fn ($c) => $c->setRelation('replies', ResearchComment::where('parent_id', $c->id)->with('user')->orderBy('created_at')->get()));
    }

    public function addComment(User $user, Model $subject, string $content, ?ResearchComment $parent = null): ResearchComment
    {
        if (! $this->mayRead($user, $subject)) {
            throw new InvalidArgumentException(__('That is not yours.'));
        }
        if (trim($content) === '') {
            throw new InvalidArgumentException(__('Write something.'));
        }
        if ($parent && ($parent->subject_table !== $subject->getTable() || $parent->subject_id !== $subject->getKey())) {
            throw new InvalidArgumentException(__('Reply to a comment of this place.'));
        }
        $comment = DB::transaction(fn () => AuditLog::actingAs($user, fn () => ResearchComment::create(['institution_id' => $user->institution_id, 'subject_table' => $subject->getTable(),
            'subject_id' => $subject->getKey(), 'parent_id' => $parent?->id, 'user_id' => $user->id, 'content' => trim($content)])));
        $owner = $this->owner($subject);
        if ($owner && $owner->id !== $user->id) {
            $this->outbox->send($owner, new ResearchMail($owner, 'A comment on your research', [], [[':who commented: ":text"', ['who' => $user->name, 'text' => mb_substr(trim($content), 0, 300)]]]));
        }

        return $comment;
    }

    public function commentStep(User $user, ResearchComment $comment, string $step): void
    {
        $subject = $this->commentSubject($comment);
        $mayManage = $comment->user_id === $user->id || ($subject && $this->owner($subject)?->id === $user->id);
        if (! $mayManage) {
            throw new InvalidArgumentException(__('Only its author or who manages it may do that.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => match ($step) {
            'resolve' => $comment->update(['is_resolved' => ! $comment->is_resolved, 'resolved_by_id' => $comment->is_resolved ? null : $user->id, 'resolved_at' => $comment->is_resolved ? null : now()]),
            'delete' => $comment->delete(),
        }));
    }

    public function commentSubject(ResearchComment $comment): ?Model
    {
        $class = self::COMMENTABLE[$comment->subject_table] ?? null;

        return $class ? $class::find($comment->subject_id) : null;
    }

    /** Whose the thing is: its author, or for a section its report's. */
    public function owner(Model $subject): ?User
    {
        return User::find($subject instanceof ResearchReportSection ? $subject->report?->user_id : $subject->getAttribute('user_id'));
    }

    /** The accounts that share an accepted membership of a research workspace with this one. */
    private function colleagues(User $user): Collection
    {
        $accepted = Vocab::BASE.'membership-status/accepted';

        return ResearchWorkspaceMember::whereHas('status', fn ($s) => $s->where('iri', $accepted))->whereIn('workspace_id',
            ResearchWorkspaceMember::where('user_id', $user->id)->whereHas('status', fn ($s) => $s->where('iri', $accepted))->select('workspace_id'))->pluck('user_id');
    }
}
