<?php

// OpenRiC Workspace - research workspaces that researchers share (roadmap phase 19d, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's CollaborationService (createWorkspace, canAccess, addMember, acceptMembership, declineMembership,
// removeMember, updateMemberRole, addResource, removeResource) and ResearchWorkspaceFileController, with
// research_workspace, its members, resources and files: a researcher makes a research workspace, invites colleagues
// of the institution by their e-mail address with a role, and they accept or decline; members put collections,
// bibliographies, saved searches, links and files in it. Roles rank as Heratio's canAccess ranks them (owner,
// administrator, editor, viewer), with contributor beside editor and member beside viewer; a contributor may add but
// not remove what others added. The owner may hand the workspace to another member. What a member opens through it is
// still shown with the member's own access; a project opens only for who may read it. Heratio's share_token is not kept:
// one joins by invitation. Discussions are the collaboration's of 19f.

namespace App\Research;

use App\Audit\AuditLog;
use App\Mail\Outbox;
use App\Mail\ResearchMail;
use App\Models\Bibliography;
use App\Models\Concept;
use App\Models\ResearchCollection;
use App\Models\ResearchFile;
use App\Models\ResearchProject;
use App\Models\ResearchWorkspace;
use App\Models\ResearchWorkspaceFile;
use App\Models\ResearchWorkspaceMember;
use App\Models\ResearchWorkspaceResource;
use App\Models\SavedSearch;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ResearchWorkspaces
{
    public const ROLES = Vocab::BASE.'workspace-member-role/';

    public const STATUSES = Vocab::BASE.'membership-status/';

    public const VISIBILITY = Vocab::BASE.'research-workspace-visibility/';

    public const RESOURCES = Vocab::BASE.'workspace-resource-type/';

    /** Heratio's role hierarchy, with contributor and member beside the roles it ranks. */
    public const LEVELS = ['owner' => 4, 'admin' => 3, 'editor' => 2, 'contributor' => 2, 'member' => 1, 'viewer' => 1];

    public function __construct(private Outbox $outbox, private ResearchFiles $files) {}

    public function create(User $user, array $data): ResearchWorkspace
    {
        $fields = $this->fields($data);

        return DB::transaction(fn () => AuditLog::actingAs($user, function () use ($user, $fields) {
            $workspace = ResearchWorkspace::create($fields + ['institution_id' => $user->institution_id, 'owner_id' => $user->id]);
            $workspace->members()->create(['user_id' => $user->id, 'role_id' => $this->concept(self::ROLES, 'owner'), 'status_id' => $this->concept(self::STATUSES, 'accepted'),
                'invited_by_id' => $user->id, 'invited_at' => now(), 'accepted_at' => now()]);

            return $workspace;
        }));
    }

    public function update(User $user, ResearchWorkspace $workspace, array $data): void
    {
        $this->must($user, $workspace, 'admin');
        $fields = $this->fields($data);
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $workspace->update($fields)));
    }

    public function delete(User $user, ResearchWorkspace $workspace): void
    {
        $this->must($user, $workspace, 'owner');
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $workspace->delete()));
    }

    /** The member row of an account, when it is an accepted member. */
    public function membership(User $user, ResearchWorkspace $workspace): ?ResearchWorkspaceMember
    {
        return $workspace->members()->where('user_id', $user->id)->whereHas('status', fn ($s) => $s->where('iri', self::STATUSES.'accepted'))->with('role')->first();
    }

    /** How far an account may go: 0 none, 1 read, 2 add, 3 manage, 4 own (Heratio's canAccess). A public workspace reads as 1 to the institution. */
    public function level(User $user, ResearchWorkspace $workspace): int
    {
        if ($workspace->institution_id !== $user->institution_id) {
            return 0;
        }
        $level = self::LEVELS[$this->membership($user, $workspace)?->role->notation] ?? 0;

        return max($level, $workspace->visibility->notation === 'public' ? 1 : 0);
    }

    /** The workspaces an account belongs to, and the public ones of its institution. */
    public function visible(User $user): Builder
    {
        return ResearchWorkspace::where('institution_id', $user->institution_id)->where(fn ($w) => $w->whereHas('members', fn ($m) => $m->where('user_id', $user->id)
            ->whereHas('status', fn ($s) => $s->where('iri', self::STATUSES.'accepted')))->orWhereHas('visibility', fn ($v) => $v->where('iri', self::VISIBILITY.'public')));
    }

    /** Invitations waiting for an account's answer. */
    public function invitations(User $user): Builder
    {
        return ResearchWorkspaceMember::where('user_id', $user->id)->whereHas('status', fn ($s) => $s->where('iri', self::STATUSES.'pending'))->with('workspace', 'role', 'invitedBy');
    }

    /** Invite an account of the institution by its e-mail address, with a role below owner. */
    public function invite(User $actor, ResearchWorkspace $workspace, string $email, string $role): ResearchWorkspaceMember
    {
        $this->must($actor, $workspace, 'admin');
        if ($role === 'owner' || ! isset(self::LEVELS[$role])) {
            throw new InvalidArgumentException(__('Choose a role from the list.'));
        }
        $invitee = User::where('institution_id', $workspace->institution_id)->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])->whereNull('disabled_at')->first()
            ?? throw new InvalidArgumentException(__('No account of the institution has that e-mail address.'));
        $existing = $workspace->members()->where('user_id', $invitee->id)->with('status')->first();
        if ($existing && in_array($existing->status->notation, ['pending', 'accepted'], true)) {
            throw new InvalidArgumentException(__('That account is a member or invited already.'));
        }
        $member = DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($workspace, $invitee, $actor, $role, $existing) {
            $fields = ['role_id' => $this->concept(self::ROLES, $role), 'status_id' => $this->concept(self::STATUSES, 'pending'), 'invited_by_id' => $actor->id, 'invited_at' => now(), 'accepted_at' => null];
            if ($existing) {
                $existing->update($fields);

                return $existing;
            }

            return $workspace->members()->create($fields + ['user_id' => $invitee->id]);
        }));
        $this->outbox->send($invitee, new ResearchMail($invitee, 'An invitation to the research workspace :name', ['name' => $workspace->name],
            [[':who invites you to the research workspace ":name".', ['who' => $actor->name, 'name' => $workspace->name]]], route('research.workspaces.index'), 'Answer the invitation'));

        return $member;
    }

    /** Accept or decline an invitation (Heratio's acceptMembership and declineMembership). */
    public function answer(User $user, ResearchWorkspaceMember $member, bool $accept): void
    {
        if ($member->user_id !== $user->id || $member->status->notation !== 'pending') {
            throw new InvalidArgumentException(__('There is no invitation to answer.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $member->update(['status_id' => $this->concept(self::STATUSES, $accept ? 'accepted' : 'declined'),
            'accepted_at' => $accept ? now() : null])));
    }

    /** Remove a member, or leave; the owner neither leaves nor is removed. */
    public function remove(User $actor, ResearchWorkspaceMember $member): void
    {
        $workspace = $member->workspace;
        if ($member->user_id !== $actor->id) {
            $this->must($actor, $workspace, 'admin');
        }
        if ($member->role->notation === 'owner') {
            throw new InvalidArgumentException(__('The owner cannot leave; hand the workspace to another member first.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $member->update(['status_id' => $this->concept(self::STATUSES, 'removed')])));
    }

    /** Change a member's role; the owner may hand the workspace over, becoming an administrator. */
    public function changeRole(User $actor, ResearchWorkspaceMember $member, string $role): void
    {
        $workspace = $member->workspace;
        $this->must($actor, $workspace, $role === 'owner' ? 'owner' : 'admin');
        if (! isset(self::LEVELS[$role]) || $member->role->notation === 'owner' || $member->status->notation !== 'accepted' && $role === 'owner') {
            throw new InvalidArgumentException(__('That role cannot be given.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $workspace, $member, $role) {
            if ($role === 'owner') {
                $workspace->members()->where('user_id', $actor->id)->update(['role_id' => $this->concept(self::ROLES, 'admin')]);
                $workspace->update(['owner_id' => $member->user_id]);
            }
            $member->update(['role_id' => $this->concept(self::ROLES, $role)]);
        }));
    }

    /** Put a collection, bibliography or saved search of one's own, a link, or one of the workspace's files in it. */
    public function addResource(User $actor, ResearchWorkspace $workspace, array $data): ResearchWorkspaceResource
    {
        $this->must($actor, $workspace, 'contributor');
        $type = (string) ($data['resource_type'] ?? '');
        $id = $data['resource_id'] ?? null;
        $title = trim((string) ($data['title'] ?? ''));
        $fields = ['description' => trim((string) ($data['description'] ?? '')) ?: null];
        match ($type) {
            'collection' => $title = $title ?: (string) (ResearchCollection::where(['id' => $id, 'user_id' => $actor->id])->value('name') ?? throw new InvalidArgumentException(__('Choose one of your own.'))),
            'bibliography' => $title = $title ?: (string) (Bibliography::where(['id' => $id, 'user_id' => $actor->id])->value('name') ?? throw new InvalidArgumentException(__('Choose one of your own.'))),
            'saved-search' => $title = $title ?: (string) (SavedSearch::where(['id' => $id, 'user_id' => $actor->id])->value('name') ?? throw new InvalidArgumentException(__('Choose one of your own.'))),
            'project' => $title = $title ?: (($p = ResearchProject::find($id)) && app(Projects::class)->level($actor, $p) >= 1 ? $p->title : throw new InvalidArgumentException(__('Choose one of your projects.'))),
            'document' => $title = $title ?: (string) (ResearchWorkspaceFile::where(['id' => $id, 'workspace_id' => $workspace->id])->with('file')->first()?->file->original_name
                ?? throw new InvalidArgumentException(__('Choose one of the workspace\'s files.'))),
            'link' => filter_var($data['external_url'] ?? '', FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $data['external_url']) && $title !== ''
                ? $fields['external_url'] = $data['external_url'] : throw new InvalidArgumentException(__('Give a web address and a title.')),
            default => throw new InvalidArgumentException(__('Choose a kind from the list.')),
        };

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $workspace->resources()->create($fields + ['resource_type_id' => $this->concept(self::RESOURCES, $type),
            'resource_id' => $type === 'link' ? null : $id, 'title' => mb_substr($title, 0, 500), 'added_by_id' => $actor->id, 'added_at' => now(),
            'sort_order' => (int) $workspace->resources()->max('sort_order') + 1])));
    }

    public function removeResource(User $actor, ResearchWorkspaceResource $resource): void
    {
        $this->mustRemove($actor, $resource->workspace, $resource->added_by_id);
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $resource->delete()));
    }

    /** The thing a resource names, as a member may open it: the model, or null when it is a link or gone. */
    public function target(ResearchWorkspaceResource $resource): ?object
    {
        return match ($resource->resourceType->notation) {
            'collection' => ResearchCollection::find($resource->resource_id),
            'bibliography' => Bibliography::find($resource->resource_id),
            'saved-search' => SavedSearch::find($resource->resource_id),
            'project' => ResearchProject::find($resource->resource_id),
            'document' => ResearchWorkspaceFile::with('file')->find($resource->resource_id),
            default => null,
        };
    }

    public function addFile(User $actor, ResearchWorkspace $workspace, UploadedFile $upload): ResearchWorkspaceFile
    {
        $this->must($actor, $workspace, 'contributor');
        app(Quotas::class)->check($actor, 'storage', null, (int) $upload->getSize());

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $workspace->files()->create(['file_id' => $this->files->keep($upload, $workspace->institution_id, $actor)->id,
            'added_by_id' => $actor->id, 'created_at' => now()])));
    }

    public function removeFile(User $actor, ResearchWorkspaceFile $file): void
    {
        $this->mustRemove($actor, $file->workspace, $file->added_by_id);
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($file) {
            $file->workspace->resources()->whereHas('resourceType', fn ($t) => $t->where('iri', self::RESOURCES.'document'))->where('resource_id', $file->id)->delete();
            $file->delete();
        }));
    }

    public function file(User $user, ResearchWorkspaceFile $file): ResearchFile
    {
        $this->must($user, $file->workspace, 'viewer');

        return $file->file;
    }

    public function must(User $user, ResearchWorkspace $workspace, string $role): void
    {
        if ($this->level($user, $workspace) < self::LEVELS[$role]) {
            throw new InvalidArgumentException(__('Your role in this research workspace does not allow that.'));
        }
    }

    /** What one added, a contributor removes; what others added, an editor or more. */
    private function mustRemove(User $user, ResearchWorkspace $workspace, ?string $addedBy): void
    {
        $level = $this->level($user, $workspace);
        if ($addedBy === $user->id ? $level < self::LEVELS['contributor'] : $level < self::LEVELS['admin'] && $this->membership($user, $workspace)?->role->notation !== 'editor') {
            throw new InvalidArgumentException(__('Your role in this research workspace does not allow that.'));
        }
    }

    private function fields(array $data): array
    {
        if (trim((string) ($data['name'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give the research workspace a name.'));
        }

        return ['name' => trim($data['name']), 'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'visibility_id' => $this->concept(self::VISIBILITY, $data['visibility'] ?? 'private')];
    }

    private function concept(string $scheme, string $notation): string
    {
        return Concept::where('iri', $scheme.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
