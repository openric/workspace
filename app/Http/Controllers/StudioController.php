<?php

// OpenRiC Workspace - the studios of a research project, and its time machine (roadmap phases 19g and 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's WritingStudioController, ReviewStudioController, PublicationStudioController, MethodStudioController,
// QuestionBuilderController, ArgumentBuilderController, SourceTriageController and TimeMachineController.

namespace App\Http\Controllers;

use App\Models\Bibliography;
use App\Models\BibliographyEntry;
use App\Models\Concept;
use App\Models\ResearchArgumentStep;
use App\Models\ResearchMethodProtocol;
use App\Models\ResearchProject;
use App\Models\ResearchPublicationSubmission;
use App\Models\ResearchReviewComment;
use App\Models\ResearchTargetJournal;
use App\Models\ResearchWritingDoc;
use App\Models\ResearchWritingSection;
use App\Research\Projects;
use App\Research\Studios;
use App\Research\TimeMachine;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class StudioController extends Controller
{
    public function __construct(private Projects $projects, private Studios $studios) {}

    // The writing studio.

    public function writing(Request $request, ResearchProject $project): View
    {
        $level = $this->read($request, $project);

        return view('studios.writing', ['project' => $project, 'level' => $level, 'docs' => $project->writingDocs()->with('docType', 'status')->withCount('sections')->get(),
            'types' => Concept::choices(Vocab::BASE.'writing-doc-type/')->get(), 'statuses' => Concept::choices(Vocab::BASE.'writing-status/')->get()]);
    }

    public function doc(Request $request, ResearchWritingDoc $doc): View|Response
    {
        $level = $this->read($request, $doc->project);
        if ($request->query('format') === 'markdown') {
            return response($this->studios->markdown($doc, Vocab::languages()), 200, ['Content-Type' => 'text/markdown; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="'.(Str::slug($doc->title) ?: 'document').'.md"']);
        }

        return view('studios.doc', ['project' => $doc->project, 'level' => $level, 'doc' => $doc->load('docType', 'status', 'sections', 'versions.createdBy'),
            'claims' => $doc->project->claims()->get(), 'entries' => BibliographyEntry::whereIn('bibliography_id', Bibliography::where('project_id', $doc->project_id)->select('id'))->get(),
            'types' => Concept::choices(Vocab::BASE.'writing-doc-type/')->get(), 'statuses' => Concept::choices(Vocab::BASE.'writing-status/')->get()]);
    }

    public function writingStep(Request $request, ResearchProject $project, string $step, ?string $id = null): RedirectResponse
    {
        $user = $request->user();
        $doc = $id && in_array($step, ['doc', 'section', 'version'], true) ? ResearchWritingDoc::where(['id' => $id, 'project_id' => $project->id])->firstOrFail() : null;
        $section = $id && in_array($step, ['update-section', 'pull', 'remove-section'], true) ? ResearchWritingSection::whereIn('doc_id', $project->writingDocs()->select('id'))->findOrFail($id) : null;
        $data = $request->validate(['title' => ['nullable', 'string', 'max:500'], 'doc_type' => ['nullable', 'string', 'max:64'], 'status' => ['nullable', 'string', 'max:64'],
            'heading' => ['nullable', 'string', 'max:500'], 'body' => ['nullable', 'string', 'max:500000'], 'sort_order' => ['nullable', 'integer'], 'note' => ['nullable', 'string', 'max:1000'],
            'kind' => ['nullable', 'in:claim,source'], 'pull_id' => ['nullable', 'uuid']]);
        if ($step === 'doc' && ! $doc) {
            try {
                $doc = $this->studios->saveDoc($user, $project, $data);
            } catch (InvalidArgumentException $e) {
                return back()->withInput()->withErrors(['research' => $e->getMessage()]);
            }

            return redirect()->route('research.studios.doc', $doc)->with('status', __('Saved.'));
        }

        return $this->attempt(fn () => match ($step) {
            'doc' => $this->studios->saveDoc($user, $project, $data, $doc),
            'section' => $this->studios->saveSection($user, $doc, $data),
            'version' => $this->studios->saveVersion($user, $doc, $data['note'] ?? null),
            'update-section' => $this->studios->saveSection($user, $section->doc, $data, $section),
            'pull' => $this->studios->pull($user, $section, (string) ($data['kind'] ?? ''), (string) ($data['pull_id'] ?? '')),
            'remove-section' => $this->studios->removeSection($user, $section),
        }, __('Saved.'));
    }

    // The review studio.

    public function review(Request $request, ResearchProject $project): View
    {
        $level = $this->read($request, $project);

        return view('studios.review', ['project' => $project, 'level' => $level, 'claims' => $project->claims()->with('status')->get(),
            'comments' => $project->reviewComments()->whereNull('thread_id')->with('author', 'claim', 'replies.author')->get()]);
    }

    public function reviewStep(Request $request, ResearchProject $project, ?ResearchReviewComment $comment = null): RedirectResponse
    {
        if ($comment) {
            abort_unless($comment->project_id === $project->id, 404);

            return $this->attempt(fn () => $this->studios->resolveComment($request->user(), $comment), __('Saved.'));
        }
        $data = $request->validate(['body' => ['required', 'string', 'max:20000'], 'claim_id' => ['nullable', 'uuid'], 'thread_id' => ['nullable', 'uuid']]);

        return $this->attempt(fn () => $this->studios->comment($request->user(), $project, $data), __('Saved.'));
    }

    // The publication studio.

    public function publication(Request $request, ResearchProject $project): View
    {
        $level = $this->read($request, $project);
        $filters = $request->only(['scope', 'open_access', 'market']);

        return view('studios.publication', ['project' => $project, 'level' => $level, 'filters' => $filters, 'venues' => $this->studios->venues($project, $filters),
            'submissions' => $project->publicationSubmissions()->with('status', 'targetJournal', 'requirements', 'responses')->get(), 'transitions' => Studios::TRANSITIONS,
            'statuses' => Concept::choices(Vocab::BASE.'publication-status/')->get()->keyBy('notation'),
            'journals' => ResearchTargetJournal::where('institution_id', $project->institution_id)->orderBy('title')->get()]);
    }

    public function publicationStep(Request $request, ResearchProject $project, string $step, ?ResearchPublicationSubmission $submission = null): RedirectResponse
    {
        abort_if($submission && $submission->project_id !== $project->id, 404);
        $user = $request->user();
        $data = $request->validate(['venue_name' => ['nullable', 'string', 'max:300'], 'target_journal_id' => ['nullable', 'uuid'], 'manuscript_title' => ['nullable', 'string', 'max:500'],
            'doi' => ['nullable', 'string', 'max:255'], 'repository_url' => ['nullable', 'url', 'max:1000'], 'notes' => ['nullable', 'string', 'max:20000'], 'to' => ['nullable', 'string', 'max:64'],
            'label' => ['nullable', 'string', 'max:255'], 'requirement_id' => ['nullable', 'uuid'], 'met' => ['nullable', 'boolean'], 'note' => ['nullable', 'string', 'max:1000'],
            'reviewer_label' => ['nullable', 'string', 'max:120'], 'point' => ['nullable', 'string', 'max:20000'], 'response' => ['nullable', 'string', 'max:20000'],
            'revision_note' => ['nullable', 'string', 'max:20000'], 'response_id' => ['nullable', 'uuid']]);

        return $this->attempt(fn () => match ($step) {
            'save' => $this->studios->submit($user, $project, $data, $submission),
            'move' => $this->studios->transition($user, $submission ?? abort(404), (string) ($data['to'] ?? '')),
            'requirement', 'tick', 'unrequire', 'response', 'unrespond', 'delete' => $this->studios->submissionStep($user, $submission ?? abort(404), $step, $data),
        }, __('Saved.'));
    }

    // The method studio.

    public function method(Request $request, ResearchProject $project): View
    {
        $level = $this->read($request, $project);

        return view('studios.method', ['project' => $project, 'level' => $level, 'templates' => $this->studios->templates($project->institution_id),
            'protocols' => $project->methodProtocols()->with('template', 'status')->get(), 'statuses' => Concept::choices(Vocab::BASE.'protocol-status/')->get(),
            'editing' => $request->filled('protocol') ? $project->methodProtocols()->with('template', 'status')->find($request->query('protocol')) : null]);
    }

    public function saveProtocol(Request $request, ResearchProject $project, ?ResearchMethodProtocol $protocol = null): RedirectResponse
    {
        abort_if($protocol && $protocol->project_id !== $project->id, 404);
        $data = $request->validate(['template' => ['nullable', 'string', 'max:64'], 'title' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', 'string', 'max:64'],
            'fields' => ['nullable', 'array'], 'fields.*' => ['nullable', 'string', 'max:20000']]);
        try {
            $saved = $this->studios->saveProtocol($request->user(), $project, $data, $protocol);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['research' => $e->getMessage()]);
        }

        return redirect()->route('research.studios.method', [$project, 'protocol' => $saved->id])->with('status', __('Saved.'));
    }

    // The question builder.

    public function question(Request $request, ResearchProject $project): View
    {
        $level = $this->read($request, $project);
        $brief = $project->questionBrief;
        $versions = $brief?->versions()->with('createdBy')->get() ?? collect();
        $shown = $request->filled('version') ? $versions->firstWhere('version_no', (int) $request->query('version')) : $versions->first();

        return view('studios.question', ['project' => $project, 'level' => $level, 'brief' => $brief, 'versions' => $versions, 'shown' => $shown,
            'diagnosis' => $shown ? Studios::diagnose($shown->toArray()) : [], 'statuses' => Concept::choices(Vocab::BASE.'brief-status/')->get()]);
    }

    public function saveBrief(Request $request, ResearchProject $project): RedirectResponse
    {
        $data = $request->validate(array_fill_keys(Studios::BRIEF, ['nullable', 'string', 'max:20000']) + ['change_reason' => ['nullable', 'string', 'max:500'], 'status' => ['nullable', 'string', 'max:64']]);

        return $this->attempt(fn () => $this->studios->saveBrief($request->user(), $project, $data), __('Saved as a new version.'));
    }

    // The argument builder.

    public function argument(Request $request, ResearchProject $project): View
    {
        $level = $this->read($request, $project);
        $argument = $project->argument;

        return view('studios.argument', ['project' => $project, 'level' => $level, 'argument' => $argument, 'steps' => $argument?->steps()->with('slot', 'claim.status')->get() ?? collect(),
            'warnings' => $argument ? $this->studios->warnings($argument, Vocab::languages()) : [], 'slots' => Concept::choices(Vocab::BASE.'argument-slot/')->get(),
            'claims' => $project->claims()->with('status')->get()]);
    }

    public function argumentStep(Request $request, ResearchProject $project, string $step, ?ResearchArgumentStep $argumentStep = null): RedirectResponse
    {
        abort_if($argumentStep && $argumentStep->argument->project_id !== $project->id, 404);
        $data = $request->validate(['title' => ['nullable', 'string', 'max:255'], 'central_thesis' => ['nullable', 'string', 'max:20000'], 'slot' => ['nullable', 'string', 'max:64'],
            'claim_id' => ['nullable', 'uuid'], 'note' => ['nullable', 'string', 'max:5000']]);

        return $this->attempt(fn () => match ($step) {
            'save' => $this->studios->saveArgument($request->user(), $project, $data),
            'add' => $this->studios->addStep($request->user(), $project, (string) ($data['slot'] ?? ''), $data['claim_id'] ?? null, $data['note'] ?? null),
            'update', 'up', 'down', 'remove' => $this->studios->stepStep($request->user(), $argumentStep ?? abort(404), $step, $data),
        }, __('Saved.'));
    }

    // Source triage.

    public function triage(Request $request, ResearchProject $project): View
    {
        $level = $this->read($request, $project);
        $board = $this->studios->board($project);
        $category = $request->query('category');

        return view('studios.triage', ['project' => $project, 'level' => $level, 'category' => $category,
            'board' => $category ? $board->filter(fn ($s) => $s['triage']?->triageCategory?->notation === $category)->values() : $board,
            'categories' => Concept::choices(Vocab::BASE.'triage-category/')->get(), 'reads' => Concept::choices(Vocab::BASE.'read-status/')->get()]);
    }

    public function saveTriage(Request $request, ResearchProject $project): RedirectResponse
    {
        $data = $request->validate(['type' => ['required', 'in:bibliography-entry,collection-item'], 'id' => ['required', 'uuid'], 'triage_category' => ['nullable', 'string', 'max:64'],
            'read_status' => ['nullable', 'string', 'max:64'], 'notes' => ['nullable', 'string', 'max:5000']]);

        return $this->attempt(fn () => $this->studios->triage($request->user(), $project, $data['type'], $data['id'], $data), __('Saved.'));
    }

    // The time machine.

    public function timeMachine(Request $request, ResearchProject $project): View
    {
        $this->read($request, $project);
        $machine = app(TimeMachine::class);
        $day = $request->query('as_of');

        return view('studios.time-machine', ['project' => $project, 'months' => $machine->byMonth($machine->timeline($project)),
            'state' => is_string($day) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) ? $machine->asOf($project, $day) : null]);
    }

    private function read(Request $request, ResearchProject $project): int
    {
        $level = $this->projects->level($request->user(), $project);
        abort_unless($level > 0, 404);

        return $level;
    }

    private function attempt(callable $do, string $done): RedirectResponse
    {
        try {
            $do();
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['research' => $e->getMessage()]);
        } catch (\UnhandledMatchError) {
            abort(404);
        }

        return back()->with('status', $done);
    }
}
