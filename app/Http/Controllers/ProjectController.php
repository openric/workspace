<?php

// OpenRiC Workspace - research projects and working together on them (roadmap phase 19f, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ResearchProjectsController, ResearchCollaborationController, ResearchMilestoneController,
// ResearchTeamController, ResearchOutputController and ProjectExportController: one's projects and invitations, a
// project with its collaborators, resources, milestones, team, outputs, clipboard, discussions, comments on its
// evidence and activity; its export; its summary opened by a share link; putting one's own research in a project;
// discussions of research workspaces and comments on annotations, diary entries and collections.

namespace App\Http\Controllers;

use App\Models\Bibliography;
use App\Models\Concept;
use App\Models\Favourite;
use App\Models\Institution;
use App\Models\ReadingRoomBooking;
use App\Models\RecordResource;
use App\Models\ResearchAnnotation;
use App\Models\ResearchCollection;
use App\Models\ResearchComment;
use App\Models\ResearchDiaryEntry;
use App\Models\ResearchDiscussion;
use App\Models\ResearcherSubmission;
use App\Models\ResearchEvidenceComment;
use App\Models\ResearchNotebook;
use App\Models\ResearchProject;
use App\Models\ResearchProjectClipboardItem;
use App\Models\ResearchProjectMember;
use App\Models\ResearchProjectMilestone;
use App\Models\ResearchProjectOutput;
use App\Models\ResearchProjectResource;
use App\Models\ResearchProjectTeamMember;
use App\Models\ResearchReport;
use App\Models\ResearchReportSection;
use App\Models\ResearchWorkspace;
use App\Models\ResearchWorkspaceFile;
use App\Models\SavedSearch;
use App\Relations\EntityKinds;
use App\Research\Annotations;
use App\Research\Bibliographies;
use App\Research\Collaboration;
use App\Research\Collections;
use App\Research\ProjectExport;
use App\Research\Projects;
use App\Research\ResearchFiles;
use App\Support\Vocab;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class ProjectController extends Controller
{
    /** What may be put in a project, by the segment of its route. */
    private const ASSIGNABLE = ['collection' => ResearchCollection::class, 'annotation' => ResearchAnnotation::class, 'diary' => ResearchDiaryEntry::class,
        'bibliography' => Bibliography::class, 'submission' => ResearcherSubmission::class, 'workspace-file' => ResearchWorkspaceFile::class,
        'search' => SavedSearch::class, 'booking' => ReadingRoomBooking::class];

    public function __construct(private Projects $projects, private Collaboration $collaboration) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        return view('projects.index', ['projects' => $this->projects->visible($user)->with('status', 'projectType', 'owner')->orderByDesc('updated_at')->get(),
            'invitations' => $this->projects->invitations($user)->get()] + $this->choices());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        try {
            $project = $this->projects->create($request->user(), $data);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['research' => $e->getMessage()]);
        }

        return redirect()->route('research.projects.show', $project)->with('status', __('Saved.'));
    }

    public function show(Request $request, ResearchProject $project): View
    {
        $user = $request->user();
        $level = $this->projects->level($user, $project);
        abort_unless($level > 0, 404);
        $this->collaboration->here($user, $project, 'project');
        $project->load('owner', 'projectType', 'status', 'visibility', 'members.user', 'members.role', 'members.status', 'members.invitedBy', 'resources.resourceType', 'resources.linkType',
            'resources.record', 'resources.addedBy', 'resources.file', 'milestones.milestoneType', 'milestones.status', 'milestones.completedBy', 'teamMembers.role', 'teamMembers.status',
            'outputs.outputType', 'outputs.status', 'outputs.identifierType', 'clipboard.record', 'clipboard.user', 'evidenceComments.author', 'evidenceComments.collection');

        return view('projects.show', ['project' => $project, 'level' => $level, 'user' => $user, 'role' => $this->projects->membership($user, $project)?->role->notation,
            'service' => $this->projects, 'summary' => $this->projects->summary($project), 'discussions' => $this->collaboration->discussions($project),
            'talk' => $this->collaboration->level($user, $project), 'activity' => $project->activity()->with('user', 'activityType')->limit(30)->get(),
            'collections' => ResearchCollection::where('project_id', $project->id)->orderBy('name')->get(),
            'mine' => ['collection' => ResearchCollection::where('user_id', $user->id)->orderBy('name')->get(), 'saved-search' => SavedSearch::where('user_id', $user->id)->orderBy('name')->get(),
                'bibliography' => Bibliography::where('user_id', $user->id)->orderBy('name')->get(), 'annotation' => ResearchAnnotation::where('user_id', $user->id)->latest()->limit(50)->get()],
            'roles' => Concept::choices(Projects::ROLES)->get(), 'kinds' => Concept::choices(Projects::RESOURCES)->get(), 'linkTypes' => Concept::choices(Vocab::BASE.'link-type/')->get(),
            'milestoneTypes' => Concept::choices(Vocab::BASE.'milestone-type/')->get(), 'milestoneStatuses' => Concept::choices(Vocab::BASE.'milestone-status/')->get(),
            'teamRoles' => Concept::choices(Vocab::BASE.'team-role/')->get(), 'teamStatuses' => Concept::choices(Vocab::BASE.'team-status/')->get(),
            'outputTypes' => Concept::choices(Vocab::BASE.'output-type/')->get(), 'identifierTypes' => Concept::choices(Vocab::BASE.'output-identifier-type/')->get(),
            'outputStatuses' => Concept::choices(Vocab::BASE.'output-status/')->get(), 'formats' => ProjectExport::FORMATS,
            'editing' => $request->query('edit')] + $this->choices());
    }

    public function step(Request $request, ResearchProject $project, string $step): RedirectResponse
    {
        $user = $request->user();
        if ($step === 'share') {
            try {
                $token = $this->projects->share($user, $project, $request->boolean('share'));
            } catch (InvalidArgumentException $e) {
                return back()->withErrors(['research' => $e->getMessage()]);
            }

            return back()->with('status', $token ? __('Anyone with this link may read its summary; it is shown only now:') : __('It is no longer shared.'))
                ->with('share_link', $token ? route('research.projects.shared', $token) : null);
        }
        $data = $request->validate(['email' => ['nullable', 'email', 'max:255'], 'role' => ['nullable', 'string', 'max:64'], 'iris' => ['nullable', 'string', 'max:200000'],
            'notes' => ['nullable', 'string', 'max:5000'], 'resource_type' => ['nullable', 'string', 'max:64'], 'resource_id' => ['nullable', 'uuid'], 'iri' => ['nullable', 'string', 'max:2048'],
            'external_url' => ['nullable', 'string', 'max:2048'], 'link_type' => ['nullable', 'string', 'max:64'], 'title' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:5000'], 'tags' => ['nullable', 'string', 'max:500'], 'file' => ['nullable', 'file', 'max:'.ResearchFiles::MAX_KILOBYTES]]);

        return $this->attempt(fn () => match ($step) {
            'update' => $this->projects->update($user, $project, $this->validated($request)),
            'invite' => $this->projects->invite($user, $project, (string) ($data['email'] ?? ''), (string) ($data['role'] ?? 'viewer')),
            'resource' => $this->projects->addResource($user, $project, $data, $request->file('file')),
            'clip' => $this->projects->clip($user, $project, preg_split('/\R/', (string) ($data['iris'] ?? '')), $data['notes'] ?? null),
            'delete' => $this->projects->delete($user, $project),
        }, $step === 'invite' ? __('Invited.') : __('Saved.'), $step === 'delete' ? 'research.projects.index' : null);
    }

    public function member(Request $request, ResearchProjectMember $member, string $step): RedirectResponse
    {
        $user = $request->user();
        $member->load('project', 'role', 'status', 'user');

        return $this->attempt(fn () => match ($step) {
            'accept' => $this->projects->answer($user, $member, true),
            'decline' => $this->projects->answer($user, $member, false),
            'remove' => $this->projects->remove($user, $member),
            'role' => $this->projects->changeRole($user, $member, (string) $request->input('role')),
        }, __('Saved.'), $step === 'remove' && $member->user_id === $user->id ? 'research.projects.index' : null);
    }

    public function removeResource(Request $request, ResearchProjectResource $resource): RedirectResponse
    {
        return $this->attempt(fn () => $this->projects->removeResource($request->user(), $resource), __('Removed.'));
    }

    /** A resource opened through the project: a document, or a collection, bibliography or saved search shown with the reader's own access. */
    public function resource(Request $request, ResearchProjectResource $resource): View|RedirectResponse|Response
    {
        $user = $request->user();
        abort_unless($this->projects->level($user, $resource->project) > 0, 404);
        $resource->load('resourceType');
        if ($resource->file) {
            return app(ResearchFiles::class)->respond($resource->file, true);
        }
        $target = $this->projects->target($resource) ?? abort(404);

        return match (true) {
            $target instanceof ResearchCollection => view('research.workspace-collection', ['resource' => $resource, 'back' => [route('research.projects.show', $resource->project), $resource->project->title],
                'collection' => $target, 'items' => app(Collections::class)->items($user, $target)->filter(fn ($i) => $i['readable'])->values()]),
            $target instanceof Bibliography => view('research.workspace-bibliography', ['resource' => $resource, 'back' => [route('research.projects.show', $resource->project), $resource->project->title],
                'bibliography' => $target->load('citationStyle', 'entries.entryType'), 'service' => app(Bibliographies::class)]),
            $target instanceof SavedSearch => redirect()->route('search', array_filter(['q' => $target->search_query] + (array) $target->search_filters)),
            default => ($subject = app(Annotations::class)->subject($target)) && app(Annotations::class)->readable($user)->whereKey($target->id)->exists()
                ? redirect()->route('research.studio', [EntityKinds::get(EntityKinds::of($subject))['segment'], $subject->getKey()]) : abort(404),
        };
    }

    public function milestone(Request $request, ResearchProject $project, ?ResearchProjectMilestone $milestone = null): RedirectResponse
    {
        abort_if($milestone && $milestone->project_id !== $project->id, 404);
        $data = $request->validate(['title' => ['required', 'string', 'max:512'], 'milestone_type' => ['nullable', 'string', 'max:64'], 'description' => ['nullable', 'string', 'max:20000'],
            'deliverable' => ['nullable', 'string', 'max:512'], 'due_date' => ['nullable', 'date_format:Y-m-d'], 'completed_date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'string', 'max:64'], 'progress_pct' => ['nullable', 'integer', 'min:0', 'max:100'], 'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000']]);

        return $this->attempt(fn () => $this->projects->saveMilestone($request->user(), $project, $data, $milestone), __('Saved.'));
    }

    public function team(Request $request, ResearchProject $project, ?ResearchProjectTeamMember $member = null): RedirectResponse
    {
        abort_if($member && $member->project_id !== $project->id, 404);
        $data = $request->validate(['person_name' => ['required', 'string', 'max:512'], 'role' => ['nullable', 'string', 'max:64'], 'affiliation' => ['nullable', 'string', 'max:512'],
            'email' => ['nullable', 'string', 'max:255'], 'orcid' => ['nullable', 'string', 'max:64'], 'is_lead' => ['nullable', 'boolean'], 'contribution_note' => ['nullable', 'string', 'max:20000'],
            'start_date' => ['nullable', 'date_format:Y-m-d'], 'end_date' => ['nullable', 'date_format:Y-m-d'], 'status' => ['nullable', 'string', 'max:64']]);

        return $this->attempt(fn () => $this->projects->saveTeamMember($request->user(), $project, $data, $member), __('Saved.'));
    }

    public function output(Request $request, ResearchProject $project, ?ResearchProjectOutput $output = null): RedirectResponse
    {
        abort_if($output && $output->project_id !== $project->id, 404);
        $data = $request->validate(['output_type' => ['nullable', 'string', 'max:64'], 'title' => ['required', 'string', 'max:512'], 'authors' => ['nullable', 'string', 'max:1024'],
            'venue' => ['nullable', 'string', 'max:512'], 'identifier_type' => ['nullable', 'string', 'max:64'], 'identifier' => ['nullable', 'string', 'max:512'],
            'identifier_url' => ['nullable', 'string', 'max:1024'], 'output_date' => ['nullable', 'date_format:Y-m-d'], 'status' => ['nullable', 'string', 'max:64'], 'notes' => ['nullable', 'string', 'max:20000']]);

        return $this->attempt(fn () => $this->projects->saveOutput($request->user(), $project, $data, $output), __('Saved.'));
    }

    /** Remove a milestone, team member or output. */
    public function remove(Request $request, string $kind, string $id): RedirectResponse
    {
        $user = $request->user();

        return $this->attempt(fn () => match ($kind) {
            'milestones' => $this->projects->deleteMilestone($user, ResearchProjectMilestone::findOrFail($id)),
            'team' => $this->projects->deleteTeamMember($user, ResearchProjectTeamMember::findOrFail($id)),
            'outputs' => $this->projects->deleteOutput($user, ResearchProjectOutput::findOrFail($id)),
        }, __('Removed.'));
    }

    public function clipboard(Request $request, ResearchProjectClipboardItem $item, string $step): RedirectResponse
    {
        return $this->attempt(fn () => $this->projects->clipStep($request->user(), $item, $step, $request->input('notes')), __('Saved.'));
    }

    public function export(Request $request, ResearchProject $project, string $format): Response
    {
        try {
            [$body, $name, $mime] = app(ProjectExport::class)->export($request->user(), $project, $format);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        return response($body, 200, ['Content-Type' => $mime, 'Content-Disposition' => 'attachment; filename="'.$name.'"']);
    }

    /** The project's summary, opened by its share link. */
    public function shared(string $token): View
    {
        $project = $this->projects->shared($token) ?? abort(404);
        $project->load('projectType', 'status', 'owner', 'milestones.status', 'teamMembers.role', 'outputs.outputType', 'outputs.status', 'outputs.identifierType');

        return view('projects.shared', ['project' => $project, 'summary' => $this->projects->summary($project), 'institution' => Institution::find($project->institution_id)]);
    }

    /** Put one's own research in a project, or take it out. */
    public function assign(Request $request, string $kind, string $id): RedirectResponse
    {
        $class = self::ASSIGNABLE[$kind] ?? abort(404);
        $thing = $class::findOrFail($id);
        $project = $request->filled('project_id') ? ResearchProject::findOrFail($request->input('project_id')) : null;

        return $this->attempt(fn () => $this->projects->assign($request->user(), $thing, $project), $project ? __('Put in the project.') : __('Taken out of the project.'));
    }

    /** Add the records among one's favourites to a project as its resources, once each (Heratio's sendToProject). */
    public function favouritesToProject(Request $request): RedirectResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['uuid'], 'project_id' => ['required', 'uuid']]);
        $project = ResearchProject::findOrFail($data['project_id']);
        $iris = RecordResource::whereIn('id', Favourite::where('user_id', $request->user()->id)->whereIn('id', $data['ids'])
            ->where('subject_table', 'record_resources')->pluck('subject_id'))->whereNotIn('id', $project->resources()->whereNotNull('record_id')->pluck('record_id'))->pluck('iri')->all();
        $added = 0;
        try {
            foreach ($iris as $iri) {
                $this->projects->addResource($request->user(), $project, ['resource_type' => 'record', 'iri' => $iri]);
                $added++;
            }
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['research' => $e->getMessage()]);
        }

        return back()->with('status', trans_choice('{0} No record was added.|{1} :count record was added.|[2,*] :count records were added.', $added, ['count' => $added]));
    }

    public function promote(Request $request, ResearchNotebook $notebook): RedirectResponse
    {
        try {
            $project = $this->projects->promote($request->user(), $notebook);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['research' => $e->getMessage()]);
        }

        return redirect()->route('research.projects.show', $project)->with('status', __('The notebook is now a project.'));
    }

    // Discussions, comments and presence.

    public function discuss(Request $request, string $home, string $id): RedirectResponse
    {
        $place = ($home === 'projects' ? ResearchProject::class : ResearchWorkspace::class)::findOrFail($id);
        $data = $request->validate(['subject' => ['nullable', 'string', 'max:500'], 'content' => ['required', 'string', 'max:20000'], 'parent_id' => ['nullable', 'uuid']]);
        $parent = ($data['parent_id'] ?? null) ? ResearchDiscussion::findOrFail($data['parent_id']) : null;

        return $this->attempt(fn () => $this->collaboration->discuss($request->user(), $place, $data, $parent), __('Saved.'));
    }

    public function discussion(Request $request, ResearchDiscussion $discussion, string $step): RedirectResponse
    {
        $data = $request->validate(['subject' => ['nullable', 'string', 'max:500'], 'content' => ['nullable', 'string', 'max:20000']]);

        return $this->attempt(fn () => $this->collaboration->discussionStep($request->user(), $discussion, $step, $data), __('Saved.'));
    }

    public function evidenceComment(Request $request, ResearchProject $project): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:20000'], 'collection_id' => ['nullable', 'uuid'], 'item_id' => ['nullable', 'uuid'], 'parent_id' => ['nullable', 'uuid']]);
        try {
            $comment = $this->collaboration->comment($request->user(), $project, $data);
        } catch (InvalidArgumentException $e) {
            return $request->expectsJson() ? response()->json(['error' => $e->getMessage()], 422) : back()->withInput()->withErrors(['research' => $e->getMessage()]);
        }

        return $request->expectsJson() ? response()->json(['id' => $comment->id], 201) : back()->with('status', __('Saved.'));
    }

    public function resolveEvidenceComment(Request $request, ResearchEvidenceComment $comment): RedirectResponse
    {
        return $this->attempt(fn () => $this->collaboration->resolveComment($request->user(), $comment), __('Saved.'));
    }

    /** Heratio's collabPoll: who is looking, and the comments since the cursor. */
    public function poll(Request $request, ResearchProject $project): JsonResponse
    {
        try {
            return response()->json($this->collaboration->poll($request->user(), $project, $request->query('since')));
        } catch (InvalidArgumentException) {
            abort(404);
        }
    }

    public function comment(Request $request, string $kind, string $id): RedirectResponse
    {
        $class = ['annotation' => ResearchAnnotation::class, 'diary' => ResearchDiaryEntry::class, 'collection' => ResearchCollection::class,
            'report' => ResearchReport::class, 'report-section' => ResearchReportSection::class][$kind] ?? abort(404);
        $subject = $class::findOrFail($id);
        $data = $request->validate(['content' => ['required', 'string', 'max:20000'], 'parent_id' => ['nullable', 'uuid']]);
        $parent = ($data['parent_id'] ?? null) ? ResearchComment::findOrFail($data['parent_id']) : null;

        return $this->attempt(fn () => $this->collaboration->addComment($request->user(), $subject, $data['content'], $parent), __('Saved.'));
    }

    public function commentStep(Request $request, ResearchComment $comment, string $step): RedirectResponse
    {
        return $this->attempt(fn () => $this->collaboration->commentStep($request->user(), $comment, $step), __('Saved.'));
    }

    private function choices(): array
    {
        return ['types' => Concept::choices(Projects::TYPES)->get(), 'statuses' => Concept::choices(Projects::STATUSES)->get(), 'visibilities' => Concept::choices(Projects::VISIBILITY)->get()];
    }

    private function validated(Request $request): array
    {
        return $request->validate(['title' => ['required', 'string', 'max:500'], 'description' => ['nullable', 'string', 'max:20000'], 'project_type' => ['nullable', 'string', 'max:64'],
            'affiliation' => ['nullable', 'string', 'max:255'], 'supervisor' => ['nullable', 'string', 'max:255'], 'funding_source' => ['nullable', 'string', 'max:255'],
            'grant_number' => ['nullable', 'string', 'max:100'], 'ethics_approval' => ['nullable', 'string', 'max:100'], 'start_date' => ['nullable', 'date_format:Y-m-d'],
            'expected_end_date' => ['nullable', 'date_format:Y-m-d'], 'actual_end_date' => ['nullable', 'date_format:Y-m-d'], 'status' => ['nullable', 'string', 'max:64'],
            'visibility' => ['nullable', 'string', 'max:64']]);
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
