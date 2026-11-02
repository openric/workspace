<?php

// OpenRiC Workspace - research workspaces that researchers share (roadmap phase 19d, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ResearchCollaborationController workspace pages and ResearchWorkspaceFileController: one's research
// workspaces and invitations, a workspace with its members, resources and files, and a resource opened through it.

namespace App\Http\Controllers;

use App\Models\Bibliography;
use App\Models\Concept;
use App\Models\ResearchCollection;
use App\Models\ResearchProject;
use App\Models\ResearchWorkspace;
use App\Models\ResearchWorkspaceFile;
use App\Models\ResearchWorkspaceMember;
use App\Models\ResearchWorkspaceResource;
use App\Models\SavedSearch;
use App\Research\Bibliographies;
use App\Research\Collaboration;
use App\Research\Collections;
use App\Research\Projects;
use App\Research\Quotas;
use App\Research\ResearchFiles;
use App\Research\ResearchWorkspaces;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class ResearchWorkspaceController extends Controller
{
    public function __construct(private ResearchWorkspaces $workspaces) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        return view('research.workspaces', ['workspaces' => $this->workspaces->visible($user)->with('visibility', 'owner')->withCount(['members' => fn ($m) => $m
            ->whereHas('status', fn ($s) => $s->where('iri', ResearchWorkspaces::STATUSES.'accepted'))])->orderBy('name')->get(),
            'invitations' => $this->workspaces->invitations($user)->get(), 'visibilities' => Concept::choices(ResearchWorkspaces::VISIBILITY)->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        try {
            $workspace = $this->workspaces->create($request->user(), $data);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['research' => $e->getMessage()]);
        }

        return redirect()->route('research.workspaces.show', $workspace)->with('status', __('Saved.'));
    }

    public function show(Request $request, ResearchWorkspace $workspace): View
    {
        $user = $request->user();
        $level = $this->workspaces->level($user, $workspace);
        abort_unless($level > 0, 404);

        return view('research.workspace', ['workspace' => $workspace->load('visibility', 'owner', 'members.user', 'members.role', 'members.status', 'members.invitedBy',
            'resources.resourceType', 'resources.addedBy', 'files.file', 'files.addedBy'), 'level' => $level, 'user' => $user, 'discussions' => app(Collaboration::class)->discussions($workspace),
            'talk' => app(Collaboration::class)->level($user, $workspace), 'role' => $this->workspaces->membership($user, $workspace)?->role->notation,
            'roles' => Concept::choices(ResearchWorkspaces::ROLES)->get(), 'visibilities' => Concept::choices(ResearchWorkspaces::VISIBILITY)->get(),
            'kinds' => Concept::choices(ResearchWorkspaces::RESOURCES)->get(),
            'mine' => ['collection' => ResearchCollection::where('user_id', $user->id)->orderBy('name')->get(), 'bibliography' => Bibliography::where('user_id', $user->id)->orderBy('name')->get(),
                'saved-search' => SavedSearch::where('user_id', $user->id)->orderBy('name')->get(),
                'project' => app(Projects::class)->visible($user)->orderBy('title')->get()]]);
    }

    public function step(Request $request, ResearchWorkspace $workspace, string $step): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate(['name' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'], 'visibility' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'], 'role' => ['nullable', 'string', 'max:64'], 'resource_type' => ['nullable', 'string', 'max:64'], 'resource_id' => ['nullable', 'uuid'],
            'external_url' => ['nullable', 'string', 'max:2048'], 'title' => ['nullable', 'string', 'max:500'], 'file' => ['nullable', 'file', 'max:'.ResearchFiles::MAX_KILOBYTES]]);

        return $this->attempt(fn () => match ($step) {
            'update' => $this->workspaces->update($user, $workspace, $data),
            'invite' => $this->workspaces->invite($user, $workspace, (string) ($data['email'] ?? ''), (string) ($data['role'] ?? 'viewer')),
            'resource' => $this->workspaces->addResource($user, $workspace, $data),
            'file' => $this->workspaces->addFile($user, $workspace, $request->file('file') ?? throw new InvalidArgumentException(__('Choose a file.'))),
            'delete' => $this->workspaces->delete($user, $workspace),
        }, $step === 'invite' ? __('Invited.') : __('Saved.'), $step === 'delete' ? 'research.workspaces.index' : null);
    }

    public function member(Request $request, ResearchWorkspaceMember $member, string $step): RedirectResponse
    {
        $user = $request->user();
        $member->load('workspace', 'role', 'status');

        return $this->attempt(fn () => match ($step) {
            'accept' => $this->workspaces->answer($user, $member, true),
            'decline' => $this->workspaces->answer($user, $member, false),
            'remove' => $this->workspaces->remove($user, $member),
            'role' => $this->workspaces->changeRole($user, $member, (string) $request->input('role')),
        }, __('Saved.'), $step === 'remove' && $member->user_id === $user->id ? 'research.workspaces.index' : null);
    }

    public function removeResource(Request $request, ResearchWorkspaceResource $resource): RedirectResponse
    {
        return $this->attempt(fn () => $this->workspaces->removeResource($request->user(), $resource), __('Removed.'));
    }

    /** A collection, bibliography or saved search opened through the workspace by a member, with the member's own access. */
    public function resource(Request $request, ResearchWorkspaceResource $resource): View|RedirectResponse|Response
    {
        $user = $request->user();
        abort_unless($this->workspaces->level($user, $resource->workspace) > 0, 404);
        $target = $this->workspaces->target($resource->load('resourceType')) ?? abort(404);

        return match (true) {
            $target instanceof ResearchCollection => view('research.workspace-collection', ['resource' => $resource, 'collection' => $target,
                'items' => app(Collections::class)->items($user, $target)->filter(fn ($i) => $i['readable'])->values()]),
            $target instanceof Bibliography => view('research.workspace-bibliography', ['resource' => $resource, 'bibliography' => $target->load('citationStyle', 'entries.entryType'),
                'service' => app(Bibliographies::class)]),
            $target instanceof ResearchProject => redirect()->route('research.projects.show', $target),
            $target instanceof SavedSearch => redirect()->route('search', array_filter(['q' => $target->search_query] + (array) $target->search_filters)),
            $target instanceof ResearchWorkspaceFile => $this->file($request, $target),
        };
    }

    public function file(Request $request, ResearchWorkspaceFile $file): Response|RedirectResponse
    {
        try {
            $kept = $this->workspaces->file($request->user(), $file);
        } catch (InvalidArgumentException) {
            abort(404);
        }
        try {
            app(Quotas::class)->download($request->user(), $file->project, $file, $kept->original_name);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['research' => $e->getMessage()]);
        }

        return app(ResearchFiles::class)->respond($kept, true);
    }

    public function removeFile(Request $request, ResearchWorkspaceFile $file): RedirectResponse
    {
        return $this->attempt(fn () => $this->workspaces->removeFile($request->user(), $file), __('Removed.'));
    }

    private function validated(Request $request): array
    {
        return $request->validate(['name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'], 'visibility' => ['required', 'string', 'max:64']]);
    }

    private function attempt(callable $do, string $done, ?string $route = null): RedirectResponse
    {
        try {
            $do();
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['research' => $e->getMessage()]);
        } catch (\UnhandledMatchError) {
            abort(404);
        }

        return ($route ? redirect()->route($route) : back())->with('status', $done);
    }
}
