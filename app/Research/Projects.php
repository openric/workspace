<?php

// OpenRiC Workspace - research projects (roadmap phase 19f, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ProjectService (projects, collaborators and invitations, resources, the clipboard, milestones and
// the activity log), ResearchMilestoneService, ResearchTeamService, ResearchOutputService, NotebookService's
// promoteToProject and the ResearchProjectsController and ResearchCollaborationController pages: a researcher's
// project, with collaborators invited by e-mail address who accept or decline, its resources (collections, saved
// searches, annotations, bibliographies, records, links, documents and notes), milestones and deliverables, its team
// (who need not have an account, with their ORCID iDs), what it gave out (articles, datasets and the like, with DOIs
// and other identifiers), a clipboard of records, and a log of what was done on it.
//
// Roles rank as Heratio's canAccess ranks them: owner, editor, contributor, viewer; a contributor removes only what
// they added. A public project is read by the institution. Heratio has two milestone tables (research_project_milestone
// and research_milestone); here they are one, with the fields of both. Heratio makes a share link that opens nothing;
// here it opens a page of the project's summary for anyone holding it, shown once and kept as a hash. A collection,
// annotation, diary entry, bibliography, submission or workspace file is put in a project by its owner, who must be a
// contributor to it; so is a saved search, a reading room booking (by its researcher), and a file of a research workspace
// (by who added it).

namespace App\Research;

use App\Audit\AuditLog;
use App\Mail\Outbox;
use App\Mail\ResearchMail;
use App\Models\Bibliography;
use App\Models\Concept;
use App\Models\ReadingRoomBooking;
use App\Models\RecordResource;
use App\Models\ResearchAnnotation;
use App\Models\ResearchCollection;
use App\Models\ResearchDiaryEntry;
use App\Models\ResearcherSubmission;
use App\Models\ResearchNotebook;
use App\Models\ResearchProject;
use App\Models\ResearchProjectActivity;
use App\Models\ResearchProjectClipboardItem;
use App\Models\ResearchProjectMember;
use App\Models\ResearchProjectMilestone;
use App\Models\ResearchProjectOutput;
use App\Models\ResearchProjectResource;
use App\Models\ResearchProjectTeamMember;
use App\Models\ResearchWorkspaceFile;
use App\Models\SavedSearch;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class Projects
{
    public const TYPES = Vocab::BASE.'research-project-type/';

    public const STATUSES = Vocab::BASE.'research-project-status/';

    public const VISIBILITY = Vocab::BASE.'research-project-visibility/';

    public const ROLES = Vocab::BASE.'project-role/';

    public const MEMBERSHIP = Vocab::BASE.'membership-status/';

    public const RESOURCES = Vocab::BASE.'project-resource-type/';

    /** Heratio's role hierarchy. */
    public const LEVELS = ['owner' => 4, 'editor' => 3, 'contributor' => 2, 'viewer' => 1];

    /** What may be put in a project by its owner: model => resource kind. */
    public const BELONGING = [ResearchCollection::class => 'collection', ResearchAnnotation::class => 'annotation', ResearchDiaryEntry::class => 'diary',
        Bibliography::class => 'bibliography', ResearcherSubmission::class => 'submission', ResearchWorkspaceFile::class => 'workspace-file',
        SavedSearch::class => 'search', ReadingRoomBooking::class => 'booking'];

    /** A milestone closed: neither overdue nor due soon. */
    private const CLOSED = ['completed', 'cancelled'];

    private const DUE_SOON_DAYS = 14;

    public function __construct(private Outbox $outbox, private ResearchFiles $files) {}

    // The project.

    public function create(User $user, array $data): ResearchProject
    {
        $fields = $this->fields($data);

        return DB::transaction(fn () => AuditLog::actingAs($user, function () use ($user, $fields) {
            $project = ResearchProject::create($fields + ['institution_id' => $user->institution_id, 'owner_id' => $user->id]);
            $project->members()->create(['user_id' => $user->id, 'role_id' => $this->concept(self::ROLES, 'owner'), 'status_id' => $this->concept(self::MEMBERSHIP, 'accepted'),
                'invited_by_id' => $user->id, 'invited_at' => now(), 'accepted_at' => now()]);
            $this->log($project, $user, 'create', $project);

            return $project;
        }));
    }

    public function update(User $user, ResearchProject $project, array $data): void
    {
        $this->must($user, $project, 'editor');
        $fields = $this->fields($data);
        DB::transaction(fn () => AuditLog::actingAs($user, function () use ($user, $project, $fields) {
            $project->update($fields);
            $this->log($project, $user, 'update', $project);
        }));
    }

    public function delete(User $user, ResearchProject $project): void
    {
        $this->must($user, $project, 'owner');
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $project->delete()));
    }

    /** How far an account may go: 0 none, 1 read, 2 add, 3 edit, 4 own. A public project reads as 1 to the institution. */
    public function level(User $user, ResearchProject $project): int
    {
        if ($project->institution_id !== $user->institution_id) {
            return 0;
        }
        $level = self::LEVELS[$this->membership($user, $project)?->role->notation] ?? 0;

        return max($level, $project->visibility->notation === 'public' ? 1 : 0);
    }

    public function membership(User $user, ResearchProject $project): ?ResearchProjectMember
    {
        return $project->members()->where('user_id', $user->id)->whereHas('status', fn ($s) => $s->where('iri', self::MEMBERSHIP.'accepted'))->with('role')->first();
    }

    /** The projects an account works on, and the public ones of its institution. */
    public function visible(User $user): Builder
    {
        return ResearchProject::where('institution_id', $user->institution_id)->where(fn ($w) => $w->whereHas('members', fn ($m) => $m->where('user_id', $user->id)
            ->whereHas('status', fn ($s) => $s->where('iri', self::MEMBERSHIP.'accepted')))->orWhereHas('visibility', fn ($v) => $v->where('iri', self::VISIBILITY.'public')));
    }

    /** The projects an account may add to: where it is a contributor or more. */
    public function contributing(User $user)
    {
        return $this->visible($user)->orderBy('title')->get()->filter(fn ($p) => $this->level($user, $p) >= self::LEVELS['contributor'])->values();
    }

    public function must(User $user, ResearchProject $project, string $role): void
    {
        if ($this->level($user, $project) < self::LEVELS[$role]) {
            throw new InvalidArgumentException(__('Your role in this project does not allow that.'));
        }
    }

    // Collaborators (Heratio's inviteCollaborator, acceptInvitation, declineInvitation, removeCollaborator, updateCollaboratorRole).

    public function invite(User $actor, ResearchProject $project, string $email, string $role): ResearchProjectMember
    {
        $this->must($actor, $project, 'editor');
        if ($role === 'owner' || ! isset(self::LEVELS[$role])) {
            throw new InvalidArgumentException(__('Choose a role from the list.'));
        }
        $invitee = User::where('institution_id', $project->institution_id)->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])->whereNull('disabled_at')->first()
            ?? throw new InvalidArgumentException(__('No account of the institution has that e-mail address.'));
        $existing = $project->members()->where('user_id', $invitee->id)->with('status')->first();
        if ($existing && in_array($existing->status->notation, ['pending', 'accepted'], true)) {
            throw new InvalidArgumentException(__('That account is a member or invited already.'));
        }
        $member = DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($project, $invitee, $actor, $role, $existing) {
            $fields = ['role_id' => $this->concept(self::ROLES, $role), 'status_id' => $this->concept(self::MEMBERSHIP, 'pending'), 'invited_by_id' => $actor->id, 'invited_at' => now(), 'accepted_at' => null];
            $member = $existing ? tap($existing)->update($fields) : $project->members()->create($fields + ['user_id' => $invitee->id]);
            $this->log($project, $actor, 'share', null, ['invited' => $invitee->name, 'role' => $role]);

            return $member;
        }));
        $this->outbox->send($invitee, new ResearchMail($invitee, 'An invitation to the research project :name', ['name' => $project->title],
            [[':who invites you to work on the research project ":name".', ['who' => $actor->name, 'name' => $project->title]]], route('research.projects.index'), 'Answer the invitation'));

        return $member;
    }

    public function answer(User $user, ResearchProjectMember $member, bool $accept): void
    {
        if ($member->user_id !== $user->id || $member->status->notation !== 'pending') {
            throw new InvalidArgumentException(__('There is no invitation to answer.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $member->update(['status_id' => $this->concept(self::MEMBERSHIP, $accept ? 'accepted' : 'declined'),
            'accepted_at' => $accept ? now() : null])));
    }

    public function remove(User $actor, ResearchProjectMember $member): void
    {
        if ($member->user_id !== $actor->id) {
            $this->must($actor, $member->project, 'editor');
        }
        if ($member->role->notation === 'owner') {
            throw new InvalidArgumentException(__('The owner cannot leave; hand the project to another collaborator first.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $member) {
            $member->update(['status_id' => $this->concept(self::MEMBERSHIP, 'removed')]);
            $this->log($member->project, $actor, 'remove', null, ['collaborator' => $member->user->name]);
        }));
    }

    /** Change a collaborator's role; the owner may hand the project over, becoming an editor. */
    public function changeRole(User $actor, ResearchProjectMember $member, string $role): void
    {
        $project = $member->project;
        $this->must($actor, $project, $role === 'owner' ? 'owner' : 'editor');
        if (! isset(self::LEVELS[$role]) || $member->role->notation === 'owner' || $member->status->notation !== 'accepted') {
            throw new InvalidArgumentException(__('That role cannot be given.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $project, $member, $role) {
            if ($role === 'owner') {
                $project->members()->where('user_id', $actor->id)->update(['role_id' => $this->concept(self::ROLES, 'editor')]);
                $project->update(['owner_id' => $member->user_id]);
            }
            $member->update(['role_id' => $this->concept(self::ROLES, $role)]);
            $this->log($project, $actor, 'share', null, ['collaborator' => $member->user->name, 'role' => $role]);
        }));
    }

    public function invitations(User $user): Builder
    {
        return ResearchProjectMember::where('user_id', $user->id)->whereHas('status', fn ($s) => $s->where('iri', self::MEMBERSHIP.'pending'))->with('project', 'role', 'invitedBy');
    }

    /** Share the project's summary by a link, or stop; the link is returned once. */
    public function share(User $user, ResearchProject $project, bool $on): ?string
    {
        $this->must($user, $project, 'editor');
        $token = $on ? Str::random(48) : null;
        DB::transaction(fn () => AuditLog::actingAs($user, function () use ($user, $project, $token) {
            $project->update(['share_token_hash' => $token ? hash('sha256', $token) : null]);
            $this->log($project, $user, 'share', null, ['link' => $token !== null]);
        }));

        return $token;
    }

    public function shared(string $token): ?ResearchProject
    {
        return ResearchProject::where('share_token_hash', hash('sha256', $token))->first();
    }

    // Resources (Heratio's addResource, linkCollection, linkSavedSearch, removeResource).

    public function addResource(User $actor, ResearchProject $project, array $data, ?UploadedFile $upload = null): ResearchProjectResource
    {
        $this->must($actor, $project, 'contributor');
        $type = (string) ($data['resource_type'] ?? '');
        $id = $data['resource_id'] ?? null;
        $title = trim((string) ($data['title'] ?? ''));
        $fields = ['description' => trim((string) ($data['description'] ?? '')) ?: null, 'notes' => trim((string) ($data['notes'] ?? '')) ?: null, 'tags' => Diary::tags($data['tags'] ?? null)];
        $own = fn (string $class, string $field) => (string) ($class::where(['id' => $id, 'user_id' => $actor->id])->value($field) ?? throw new InvalidArgumentException(__('Choose one of your own.')));
        match ($type) {
            'collection' => $title = $title ?: $own(ResearchCollection::class, 'name'),
            'saved-search' => $title = $title ?: $own(SavedSearch::class, 'name'),
            'bibliography' => $title = $title ?: $own(Bibliography::class, 'name'),
            'annotation' => $title = $title ?: ($own(ResearchAnnotation::class, 'title') ?: __('Annotation')),
            'record' => [$fields['record_id'], $title] = (function () use ($actor, $data, $title) {
                $record = RecordResource::where(['institution_id' => $actor->institution_id, 'iri' => trim((string) ($data['iri'] ?? ''))])->whereNull('deleted_at')->first();
                $record && EntityKinds::readable($actor, $record) || throw new InvalidArgumentException(__('No record has that IRI.'));

                return [$record->id, $title ?: EntityKinds::label($record)];
            })(),
            'external-link' => filter_var($data['external_url'] ?? '', FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $data['external_url']) && $title !== ''
                ? [$fields['external_url'], $fields['link_type_id']] = [$data['external_url'], ($data['link_type'] ?? '') !== '' ? $this->concept(Vocab::BASE.'link-type/', $data['link_type']) : null]
                : throw new InvalidArgumentException(__('Give a web address and a title.')),
            'document' => $upload ? [$fields['file_id'], $title] = [$this->files->keep($upload, $project->institution_id, $actor)->id, $title ?: mb_substr($upload->getClientOriginalName(), 0, 255)]
                : throw new InvalidArgumentException(__('Choose a file.')),
            'note' => $title !== '' || $fields['notes'] !== null ?: throw new InvalidArgumentException(__('Write the note.')),
            default => throw new InvalidArgumentException(__('Choose a kind from the list.')),
        };

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $project, $type, $id, $title, $fields) {
            $resource = $project->resources()->create($fields + ['resource_type_id' => $this->concept(self::RESOURCES, $type),
                'resource_id' => in_array($type, ['collection', 'saved-search', 'bibliography', 'annotation'], true) ? $id : null, 'title' => mb_substr($title ?: __('Note'), 0, 500),
                'added_by_id' => $actor->id, 'added_at' => now(), 'sort_order' => (int) $project->resources()->max('sort_order') + 1]);
            $this->log($project, $actor, 'collect', $resource, ['title' => $resource->title]);

            return $resource;
        }));
    }

    public function removeResource(User $actor, ResearchProjectResource $resource): void
    {
        $this->mustRemove($actor, $resource->project, $resource->added_by_id);
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $resource) {
            $resource->delete();
            $this->log($resource->project, $actor, 'remove', null, ['title' => $resource->title]);
        }));
    }

    /** The thing a resource names, when it is one of the Workspace's. */
    public function target(ResearchProjectResource $resource): ?Model
    {
        return match ($resource->resourceType->notation) {
            'collection' => ResearchCollection::find($resource->resource_id),
            'saved-search' => SavedSearch::find($resource->resource_id),
            'bibliography' => Bibliography::find($resource->resource_id),
            'annotation' => ResearchAnnotation::find($resource->resource_id),
            default => null,
        };
    }

    /** Put one's own collection, annotation, diary entry, bibliography or submission in a project, or take it out. */
    public function assign(User $user, Model $thing, ?ResearchProject $project): void
    {
        $owner = $thing instanceof ReadingRoomBooking ? $thing->researcher?->user_id : ($thing->getAttribute('user_id') ?? $thing->getAttribute('added_by_id'));
        if (! isset(self::BELONGING[$thing::class]) || $owner !== $user->id) {
            throw new InvalidArgumentException(__('That is not yours.'));
        }
        if ($project) {
            $this->must($user, $project, 'contributor');
        }
        DB::transaction(fn () => AuditLog::actingAs($user, function () use ($user, $thing, $project) {
            $thing->update(['project_id' => $project?->id]);
            if ($project) {
                $this->log($project, $user, 'collect', $thing, ['kind' => self::BELONGING[$thing::class]]);
            }
        }));
    }

    // Milestones (Heratio's addMilestone, updateMilestone, completeMilestone, deleteMilestone and ResearchMilestoneService).

    public function saveMilestone(User $actor, ResearchProject $project, array $data, ?ResearchProjectMilestone $milestone = null): ResearchProjectMilestone
    {
        $this->must($actor, $project, 'contributor');
        if (trim((string) ($data['title'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give the milestone a title.'));
        }
        $status = (string) ($data['status'] ?? $milestone?->status->notation ?? 'planned');
        $completed = $status === 'completed' ? (($data['completed_date'] ?? '') ?: ($milestone?->completed_date?->toDateString() ?? now(config('workspace.timezone'))->toDateString())) : (($data['completed_date'] ?? '') ?: null);
        $fields = ['title' => trim($data['title']), 'milestone_type_id' => $this->concept(Vocab::BASE.'milestone-type/', (string) ($data['milestone_type'] ?? 'milestone')),
            'description' => trim((string) ($data['description'] ?? '')) ?: null, 'deliverable' => trim((string) ($data['deliverable'] ?? '')) ?: null,
            'due_date' => ($data['due_date'] ?? '') ?: null, 'completed_date' => $completed, 'status_id' => $this->concept(Vocab::BASE.'milestone-status/', $status),
            'progress_pct' => $status === 'completed' ? 100 : max(0, min(100, (int) ($data['progress_pct'] ?? 0))), 'sort_order' => (int) ($data['sort_order'] ?? $milestone?->sort_order ?? 0)];

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $project, $milestone, $fields, $status) {
            if ($status === 'completed' && $milestone?->status->notation !== 'completed') {
                $fields['completed_by_id'] = $actor->id;
            }
            $milestone = $milestone ? tap($milestone)->update($fields) : $project->milestones()->create($fields + ['created_by_id' => $actor->id]);
            $this->log($project, $actor, 'milestone', $milestone, ['status' => $status]);

            return $milestone;
        }));
    }

    public function deleteMilestone(User $actor, ResearchProjectMilestone $milestone): void
    {
        $this->mustRemove($actor, $milestone->project, $milestone->created_by_id);
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $milestone->delete()));
    }

    public function isOverdue(ResearchProjectMilestone $m): bool
    {
        return $m->due_date !== null && ! in_array($m->status->notation, self::CLOSED, true) && $m->due_date->lt(now(config('workspace.timezone'))->startOfDay());
    }

    public function isDueSoon(ResearchProjectMilestone $m): bool
    {
        $today = now(config('workspace.timezone'))->startOfDay();

        return $m->due_date !== null && ! in_array($m->status->notation, self::CLOSED, true) && $m->due_date->gte($today) && $m->due_date->lte($today->copy()->addDays(self::DUE_SOON_DAYS));
    }

    // The team (ResearchTeamService).

    public function saveTeamMember(User $actor, ResearchProject $project, array $data, ?ResearchProjectTeamMember $member = null): ResearchProjectTeamMember
    {
        $this->must($actor, $project, 'contributor');
        if (trim((string) ($data['person_name'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give the person\'s name.'));
        }
        $orcid = ($data['orcid'] ?? '') === '' || $data['orcid'] === null ? null : (self::orcid((string) $data['orcid']) ?? throw new InvalidArgumentException(__('That is not an ORCID iD.')));
        if (($data['email'] ?? '') !== '' && $data['email'] !== null && ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException(__('That is not an e-mail address.'));
        }
        if (($data['start_date'] ?? null) && ($data['end_date'] ?? null) && $data['end_date'] < $data['start_date']) {
            throw new InvalidArgumentException(__('The end date comes before the start.'));
        }
        $account = ($data['email'] ?? '') ? User::where('institution_id', $project->institution_id)->whereRaw('lower(email) = ?', [mb_strtolower((string) $data['email'])])->value('id') : null;
        $fields = ['person_name' => trim($data['person_name']), 'user_id' => $account, 'role_id' => $this->concept(Vocab::BASE.'team-role/', (string) ($data['role'] ?? 'researcher')),
            'affiliation' => trim((string) ($data['affiliation'] ?? '')) ?: null, 'email' => trim((string) ($data['email'] ?? '')) ?: null, 'orcid' => $orcid, 'is_lead' => ! empty($data['is_lead']),
            'contribution_note' => trim((string) ($data['contribution_note'] ?? '')) ?: null, 'start_date' => ($data['start_date'] ?? '') ?: null, 'end_date' => ($data['end_date'] ?? '') ?: null,
            'status_id' => $this->concept(Vocab::BASE.'team-status/', (string) ($data['status'] ?? 'active'))];

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $project, $member, $fields) {
            $member = $member ? tap($member)->update($fields) : $project->teamMembers()->create($fields + ['created_by_id' => $actor->id]);
            $this->log($project, $actor, 'team', $member, ['name' => $member->person_name]);

            return $member;
        }));
    }

    public function deleteTeamMember(User $actor, ResearchProjectTeamMember $member): void
    {
        $this->mustRemove($actor, $member->project, $member->created_by_id);
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $member->delete()));
    }

    /** An ORCID iD as ####-####-####-###X, from a bare iD or its link; null unless its check digit holds (ISO 7064 11,2). */
    public static function orcid(string $value): ?string
    {
        $raw = preg_replace('#^(https?://)?(www\.)?orcid\.org/#i', '', trim($value)) ?? '';
        $compact = strtoupper(str_replace(['-', ' '], '', $raw));
        if (! preg_match('/^[0-9]{15}[0-9X]$/', $compact)) {
            return null;
        }
        $total = 0;
        foreach (str_split(substr($compact, 0, 15)) as $digit) {
            $total = ($total + (int) $digit) * 2;
        }
        $check = (12 - $total % 11) % 11;

        return ($check === 10 ? 'X' : (string) $check) === $compact[15] ? implode('-', str_split($compact, 4)) : null;
    }

    // Outputs (ResearchOutputService).

    public function saveOutput(User $actor, ResearchProject $project, array $data, ?ResearchProjectOutput $output = null): ResearchProjectOutput
    {
        $this->must($actor, $project, 'contributor');
        if (trim((string) ($data['title'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give the output a title.'));
        }
        $url = trim((string) ($data['identifier_url'] ?? ''));
        if ($url !== '' && (! filter_var($url, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $url))) {
            throw new InvalidArgumentException(__('Give a web address.'));
        }
        $fields = ['output_type_id' => $this->concept(Vocab::BASE.'output-type/', (string) ($data['output_type'] ?? 'journal-article')), 'title' => trim($data['title']),
            'authors' => trim((string) ($data['authors'] ?? '')) ?: null, 'venue' => trim((string) ($data['venue'] ?? '')) ?: null,
            'identifier_type_id' => ($data['identifier_type'] ?? '') !== '' ? $this->concept(Vocab::BASE.'output-identifier-type/', (string) $data['identifier_type']) : null,
            'identifier' => trim((string) ($data['identifier'] ?? '')) ?: null, 'identifier_url' => $url ?: null, 'output_date' => ($data['output_date'] ?? '') ?: null,
            'status_id' => $this->concept(Vocab::BASE.'output-status/', (string) ($data['status'] ?? 'planned')), 'notes' => trim((string) ($data['notes'] ?? '')) ?: null];

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $project, $output, $fields) {
            $output = $output ? tap($output)->update($fields) : $project->outputs()->create($fields + ['created_by_id' => $actor->id]);
            $this->log($project, $actor, 'output', $output, ['title' => $output->title]);

            return $output;
        }));
    }

    public function deleteOutput(User $actor, ResearchProjectOutput $output): void
    {
        $this->mustRemove($actor, $output->project, $output->created_by_id);
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $output->delete()));
    }

    /** Heratio's resolveUrl: the explicit address, or one made from the identifier. */
    public static function outputUrl(ResearchProjectOutput $output): ?string
    {
        if ($output->identifier_url) {
            return $output->identifier_url;
        }
        $id = trim((string) $output->identifier);
        if ($id === '') {
            return null;
        }

        return match ($output->identifierType?->notation) {
            'doi' => 'https://doi.org/'.preg_replace('#^(https?://(dx\.)?doi\.org/|doi:)#i', '', $id),
            'handle' => 'https://hdl.handle.net/'.ltrim($id, '/'),
            'isbn' => 'https://search.worldcat.org/search?q=bn:'.rawurlencode(preg_replace('/[^0-9Xx]/', '', $id) ?: $id),
            'url' => preg_match('#^https?://#i', $id) ? $id : null,
            default => null,
        };
    }

    // The clipboard (Heratio's addClipboardItems, toggleClipboardPin, removeClipboardItem, updateClipboardNotes).

    /** @return int how many records were put on it */
    public function clip(User $user, ResearchProject $project, array $iris, ?string $notes = null): int
    {
        $this->must($user, $project, 'contributor');
        $records = RecordResource::where('institution_id', $user->institution_id)->whereIn('iri', array_map('trim', $iris))->whereNull('deleted_at')->get()
            ->filter(fn ($r) => EntityKinds::readable($user, $r));

        return DB::transaction(fn () => AuditLog::actingAs($user, function () use ($user, $project, $records, $notes) {
            $added = 0;
            foreach ($records as $record) {
                if (! $project->clipboard()->where(['user_id' => $user->id, 'record_id' => $record->id])->exists()) {
                    $project->clipboard()->create(['user_id' => $user->id, 'record_id' => $record->id, 'notes' => trim((string) $notes) ?: null, 'created_at' => now()]);
                    $added++;
                }
            }
            if ($added > 0) {
                $this->log($project, $user, 'clipboard-add', null, ['count' => $added]);
            }

            return $added;
        }));
    }

    public function clipStep(User $user, ResearchProjectClipboardItem $item, string $step, ?string $notes = null): void
    {
        if ($item->user_id !== $user->id) {
            throw new InvalidArgumentException(__('That is not yours.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => match ($step) {
            'pin' => $item->update(['is_pinned' => ! $item->is_pinned]),
            'notes' => $item->update(['notes' => trim((string) $notes) ?: null]),
            'remove' => $item->delete(),
        }));
    }

    // Promoting a notebook (NotebookService::promoteToProject).

    /** A notebook becomes a project: its records a collection of the project, its notes the project's notes. Once only. */
    public function promote(User $user, ResearchNotebook $notebook): ResearchProject
    {
        app(Notebooks::class)->mustOwn($user, $notebook);
        if ($notebook->promoted_to_project_id && $notebook->promotedTo) {
            return $notebook->promotedTo;
        }

        return DB::transaction(function () use ($user, $notebook) {
            $project = $this->create($user, ['title' => $notebook->title ?: __('Promoted notebook'), 'description' => $notebook->summary, 'project_type' => 'public', 'status' => 'active']);
            $items = $notebook->items()->with('itemType', 'sourceRecord')->get();
            $pins = $items->filter(fn ($i) => $i->itemType->notation === 'source-pin' && $i->sourceRecord);
            AuditLog::actingAs($user, function () use ($user, $notebook, $project, $items, $pins) {
                if ($pins->isNotEmpty()) {
                    $collection = ResearchCollection::create(['institution_id' => $user->institution_id, 'user_id' => $user->id, 'project_id' => $project->id,
                        'name' => __('Promoted from notebook: :title', ['title' => $notebook->title]), 'description' => __('Made when this notebook was promoted to a project.')]);
                    foreach ($pins->unique('source_record_id') as $pin) {
                        $collection->items()->create(['subject_table' => 'record_resources', 'subject_id' => $pin->source_record_id, 'notes' => $pin->title,
                            'reference_code' => $pin->sourceRecord->referenceCode(), 'sort_order' => $pin->sort_order]);
                    }
                    $project->resources()->create(['resource_type_id' => $this->concept(self::RESOURCES, 'collection'), 'resource_id' => $collection->id, 'title' => $collection->name,
                        'added_by_id' => $user->id, 'added_at' => now()]);
                }
                foreach ($items->filter(fn ($i) => $i->itemType->notation === 'note') as $note) {
                    $project->resources()->create(['resource_type_id' => $this->concept(self::RESOURCES, 'note'), 'title' => mb_substr($note->title ?: __('Note'), 0, 500), 'notes' => $note->body,
                        'added_by_id' => $user->id, 'added_at' => now(), 'sort_order' => $note->sort_order]);
                }
                $notebook->update(['promoted_to_project_id' => $project->id, 'promoted_at' => now()]);
            });

            return $project;
        });
    }

    // The activity log (Heratio's logActivity and getProjectActivity).

    public function log(ResearchProject $project, ?User $user, string $type, ?Model $entity = null, array $details = []): void
    {
        ResearchProjectActivity::create(['project_id' => $project->id, 'user_id' => $user?->id, 'activity_type_id' => $this->concept(Vocab::BASE.'project-activity-type/', $type),
            'entity_table' => $entity?->getTable(), 'entity_id' => $entity?->getKey(), 'entity_title' => $entity ? mb_substr((string) ($entity->getAttribute('title') ?? $entity->getAttribute('name') ?? $entity->getAttribute('person_name') ?? ''), 0, 500) ?: null : null,
            'details' => $details ?: null, 'created_at' => now()]);
    }

    /** A summary of the project, as its share link and export give it, and Heratio's summaries of milestones, team and outputs. */
    public function summary(ResearchProject $project): array
    {
        $project->loadMissing('milestones.status', 'teamMembers.role', 'teamMembers.status', 'outputs.outputType', 'outputs.status', 'outputs.identifierType');
        $count = fn ($items, string $by) => $items->groupBy(fn ($i) => $i->{$by}->notation)->map->count()->all();

        return ['milestones' => ['total' => $project->milestones->count(), 'by_status' => $count($project->milestones, 'status'),
            'overdue' => $project->milestones->filter(fn ($m) => $this->isOverdue($m))->count(), 'due_soon' => $project->milestones->filter(fn ($m) => $this->isDueSoon($m))->count(),
            'progress' => $project->milestones->isEmpty() ? 0 : (int) round($project->milestones->avg('progress_pct'))],
            'team' => ['total' => $project->teamMembers->count(), 'active' => $project->teamMembers->filter(fn ($m) => $m->status->notation === 'active')->count(),
                'by_role' => $count($project->teamMembers, 'role'), 'leads' => $project->teamMembers->where('is_lead', true)->pluck('person_name')->all()],
            'outputs' => ['total' => $project->outputs->count(), 'by_type' => $count($project->outputs, 'outputType'), 'by_status' => $count($project->outputs, 'status')]];
    }

    /** What one added, a contributor removes; what others added, an editor or more. */
    private function mustRemove(User $user, ResearchProject $project, ?string $addedBy): void
    {
        $this->must($user, $project, $addedBy === $user->id ? 'contributor' : 'editor');
    }

    private function fields(array $data): array
    {
        if (trim((string) ($data['title'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give the project a title.'));
        }
        foreach (['start_date', 'expected_end_date', 'actual_end_date'] as $d) {
            $data[$d] = ($data[$d] ?? '') ?: null;
        }
        if ($data['start_date'] && $data['expected_end_date'] && $data['expected_end_date'] < $data['start_date']) {
            throw new InvalidArgumentException(__('The end date comes before the start.'));
        }
        $text = fn (string $k) => trim((string) ($data[$k] ?? '')) ?: null;

        return ['title' => mb_substr(trim($data['title']), 0, 500), 'description' => $text('description'), 'project_type_id' => $this->concept(self::TYPES, (string) ($data['project_type'] ?? 'personal')),
            'affiliation' => $text('affiliation'), 'supervisor' => $text('supervisor'), 'funding_source' => $text('funding_source'), 'grant_number' => $text('grant_number'),
            'ethics_approval' => $text('ethics_approval'), 'start_date' => $data['start_date'], 'expected_end_date' => $data['expected_end_date'], 'actual_end_date' => $data['actual_end_date'],
            'status_id' => $this->concept(self::STATUSES, (string) ($data['status'] ?? 'planning')), 'visibility_id' => $this->concept(self::VISIBILITY, (string) ($data['visibility'] ?? 'private'))];
    }

    private function concept(string $scheme, string $notation): string
    {
        return Concept::where('iri', $scheme.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
