<?php

// OpenRiC Workspace - the analysis of research: snapshots, the timeline and map, field alerts and the RiC bridge of a
// project; source assessments; entity resolution; searches across fonds; research reports and their peer review;
// and the use of the research services (roadmap phase 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ResearchProjectOutputsController (snapshots, timeline builder, map builder), FieldAlertController,
// ResearchEntityResolutionController, the cross-fonds search, ResearchReportsController and
// ResearchAnalyticsController.

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Agent;
use App\Models\Bibliography;
use App\Models\Concept;
use App\Models\Place;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\ResearchCollection;
use App\Models\ResearchEntityResolution;
use App\Models\ResearchFieldAlert;
use App\Models\ResearchFieldWatch;
use App\Models\ResearchMapPoint;
use App\Models\ResearchPeerReview;
use App\Models\ResearchProject;
use App\Models\ResearchReport;
use App\Models\ResearchReportSection;
use App\Models\ResearchReportTemplate;
use App\Models\ResearchSnapshot;
use App\Models\ResearchTimelineEvent;
use App\Relations\EntityKinds;
use App\Research\Analytics;
use App\Research\FieldAlerts;
use App\Research\ProjectAnalysis;
use App\Research\Projects;
use App\Research\Reports;
use App\Research\RicBridge;
use App\Research\Sources;
use App\Support\Vocab;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class AnalysisController extends Controller
{
    public function __construct(private Projects $projects, private ProjectAnalysis $analysis) {}

    // Snapshots.

    public function snapshots(Request $request, ResearchProject $project): View
    {
        $level = $this->read($request, $project);

        return view('analysis.snapshots', ['project' => $project, 'level' => $level, 'snapshots' => $project->snapshots()->with('status', 'user')->get()]);
    }

    public function snapshot(Request $request, ResearchSnapshot $snapshot): View
    {
        $level = $this->read($request, $snapshot->project);
        $other = $request->filled('with') ? ResearchSnapshot::where(['id' => $request->query('with'), 'project_id' => $snapshot->project_id])->first() : null;

        return view('analysis.snapshot', ['project' => $snapshot->project, 'level' => $level, 'snapshot' => $snapshot->load('items', 'status', 'user'),
            'others' => $snapshot->project->snapshots()->whereKeyNot($snapshot->id)->get(), 'compare' => $other ? ['with' => $other, 'diff' => $this->analysis->compare($other, $snapshot)] : null]);
    }

    public function snapshotStep(Request $request, ResearchProject $project, string $step, ?ResearchSnapshot $snapshot = null): RedirectResponse
    {
        abort_if($snapshot && $snapshot->project_id !== $project->id, 404);
        $data = $request->validate(['title' => ['nullable', 'string', 'max:500'], 'description' => ['nullable', 'string', 'max:5000']]);

        return $this->attempt(fn () => match ($step) {
            'take' => $this->analysis->snapshot($request->user(), $project, $data),
            'retake' => $this->analysis->retake($request->user(), $snapshot ?? abort(404)),
            'freeze', 'archive' => $this->analysis->freeze($request->user(), $snapshot ?? abort(404), $step === 'freeze' ? 'frozen' : 'archived'),
            'delete' => $this->analysis->remove($request->user(), $snapshot ?? abort(404)),
        }, __('Saved.'), $step === 'delete' ? ['research.analysis.snapshots', $project] : null);
    }

    // The timeline and the map.

    public function timeline(Request $request, ResearchProject $project): View|JsonResponse
    {
        $level = $this->read($request, $project);
        $events = $project->timelineEvents()->with('dateType')->get();
        if ($request->wantsJson() || $request->query('format') === 'json') {
            return response()->json($events->map(fn ($e) => ['id' => $e->id, 'label' => $e->label, 'start' => $e->date_start->toDateString(), 'end' => $e->date_end?->toDateString(),
                'type' => $e->dateType->notation, 'description' => $e->description, 'color' => $e->color])->values());
        }

        return view('analysis.timeline', ['project' => $project, 'level' => $level, 'events' => $events, 'types' => Concept::choices(Vocab::BASE.'timeline-date-type/')->get(),
            'collections' => ResearchCollection::where('project_id', $project->id)->orderBy('name')->get()]);
    }

    public function map(Request $request, ResearchProject $project): View|JsonResponse
    {
        $level = $this->read($request, $project);
        if ($request->wantsJson() || $request->query('format') === 'geojson') {
            return response()->json($this->analysis->geojson($project), 200, ['Content-Type' => 'application/geo+json']);
        }

        return view('analysis.map', ['project' => $project, 'level' => $level, 'points' => $project->mapPoints()->get()]);
    }

    public function plot(Request $request, ResearchProject $project, string $kind, ?string $id = null): RedirectResponse
    {
        $user = $request->user();
        $thing = $id ? ($kind === 'events' ? ResearchTimelineEvent::class : ResearchMapPoint::class)::where(['id' => $id, 'project_id' => $project->id])->firstOrFail() : null;
        if ($request->boolean('remove')) {
            return $this->attempt(fn () => $this->analysis->remove($user, $thing ?? abort(404)), __('Removed.'));
        }
        if ($kind === 'from-collection') {
            $collection = ResearchCollection::where(['id' => $request->input('collection_id'), 'project_id' => $project->id])->first();
            try {
                $n = $this->analysis->fromCollection($user, $project, $collection ?? throw new InvalidArgumentException(__('Choose a collection of this project.')));
            } catch (InvalidArgumentException $e) {
                return back()->withErrors(['research' => $e->getMessage()]);
            }

            return back()->with('status', trans_choice('{0} No event was added.|{1} :count event was added.|[2,*] :count events were added.', $n, ['count' => $n]));
        }
        $data = $request->validate(['label' => ['nullable', 'string', 'max:500'], 'description' => ['nullable', 'string', 'max:5000'], 'date_start' => ['nullable', 'date_format:Y-m-d'],
            'date_end' => ['nullable', 'date_format:Y-m-d'], 'date_type' => ['nullable', 'string', 'max:64'], 'color' => ['nullable', 'string', 'max:7'], 'position' => ['nullable', 'integer'],
            'latitude' => ['nullable', 'numeric'], 'longitude' => ['nullable', 'numeric'], 'place_name' => ['nullable', 'string', 'max:500'],
            'date_valid_from' => ['nullable', 'date_format:Y-m-d'], 'date_valid_to' => ['nullable', 'date_format:Y-m-d']]);

        return $this->attempt(fn () => $kind === 'events' ? $this->analysis->saveEvent($user, $project, $data, $thing) : $this->analysis->savePoint($user, $project, $data, $thing), __('Saved.'));
    }

    // Field alerts.

    public function alerts(Request $request, ResearchProject $project): View
    {
        $level = $this->read($request, $project);

        return view('analysis.alerts', ['project' => $project, 'level' => $level, 'watches' => $project->fieldWatches()->with('addedBy')->get(),
            'alerts' => $project->fieldAlerts()->with('alertType', 'watch')->get()]);
    }

    public function alertStep(Request $request, ResearchProject $project, string $step): RedirectResponse
    {
        $user = $request->user();
        $service = app(FieldAlerts::class);
        if ($step === 'scan') {
            try {
                $this->projects->must($user, $project, 'contributor');
            } catch (InvalidArgumentException $e) {
                return back()->withErrors(['research' => $e->getMessage()]);
            }
            $n = $service->scanProject($project);

            return back()->with('status', trans_choice('{0} Nothing new.|{1} :count alert was raised.|[2,*] :count alerts were raised.', $n, ['count' => $n]));
        }
        if ($step === 'seed') {
            try {
                $n = $service->fromBibliographies($user, $project);
            } catch (InvalidArgumentException $e) {
                return back()->withErrors(['research' => $e->getMessage()]);
            }

            return back()->with('status', trans_choice('{0} No new work is watched.|{1} :count work is now watched.|[2,*] :count works are now watched.', $n, ['count' => $n]));
        }

        return $this->attempt(fn () => match ($step) {
            'watch' => $service->watch($user, $project, (string) $request->input('doi'), $request->input('title'), 'by hand'),
            'unwatch' => $service->unwatch($user, ResearchFieldWatch::where(['id' => $request->input('watch_id'), 'project_id' => $project->id])->firstOrFail()),
            'read' => $service->read($user, $project, $request->filled('alert_id') ? ResearchFieldAlert::findOrFail($request->input('alert_id')) : null),
        }, __('Saved.'));
    }

    /** Heratio's RiC bridge, done on purpose. */
    public function describe(Request $request, ResearchProject $project): RedirectResponse
    {
        $data = $request->validate(['performer_iri' => ['nullable', 'string', 'max:2048'], 'language' => ['nullable', 'string', 'max:35']]);
        try {
            $activity = app(RicBridge::class)->describe($request->user(), $project, $data['performer_iri'] ?? null, ($data['language'] ?? '') ?: 'en');
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['research' => $e->getMessage()]);
        }

        return redirect()->route('activities.show', $activity)->with('status', __('The project is described in the catalogue, as a draft.'));
    }

    // Source assessments.

    public function assessment(Request $request, string $record): View
    {
        $unit = RecordResource::where('institution_id', $request->user()->institution_id)->whereNull('deleted_at')->findOrFail($record);
        abort_unless(EntityKinds::readable($request->user(), $unit), 404);

        return view('analysis.assessment', ['record' => $unit, 'assessments' => app(Sources::class)->assessments($unit), 'kinds' => Concept::choices(Vocab::BASE.'source-kind/')->get(),
            'forms' => Concept::choices(Vocab::BASE.'source-form/')->get(), 'completeness' => Concept::choices(Vocab::BASE.'source-completeness/')->get()]);
    }

    public function assess(Request $request, string $record): RedirectResponse
    {
        $unit = RecordResource::where('institution_id', $request->user()->institution_id)->findOrFail($record);
        $data = $request->validate(['source_kind' => ['required', 'string', 'max:64'], 'source_form' => ['required', 'string', 'max:64'], 'completeness' => ['required', 'string', 'max:64'],
            'trust_score' => ['nullable', 'integer', 'min:0', 'max:100'], 'rationale' => ['nullable', 'string', 'max:20000'], 'bias_context' => ['nullable', 'string', 'max:20000']]);

        return $this->attempt(fn () => app(Sources::class)->assess($request->user(), $unit, $data), __('Saved.'));
    }

    // Entity resolution.

    public function resolutions(Request $request): View
    {
        $user = $request->user();
        $sources = app(Sources::class);
        $status = $request->query('status', 'proposed');
        $rows = ResearchEntityResolution::where('institution_id', $user->institution_id)->with('status', 'proposer', 'resolver')
            ->when($status !== 'all', fn ($q) => $q->whereHas('status', fn ($s) => $s->where('iri', Vocab::BASE.'resolution-status/'.$status)))->latest()->get()
            ->map(fn ($r) => ['resolution' => $r, 'entities' => $sources->entities($r), 'may' => $sources->mayResolve($user, $r), 'claims' => $sources->claimsBetween($r)])
            ->filter(fn ($r) => $r['entities'][0] && $r['entities'][1] && EntityKinds::readable($user, $r['entities'][0]) && EntityKinds::readable($user, $r['entities'][1]))->values();

        return view('analysis.resolutions', ['rows' => $rows, 'status' => $status, 'statuses' => Concept::choices(Vocab::BASE.'resolution-status/')->get()]);
    }

    public function resolution(Request $request, string $step, ?ResearchEntityResolution $resolution = null): RedirectResponse
    {
        $user = $request->user();
        $sources = app(Sources::class);
        $data = $request->validate(['a' => ['nullable', 'string', 'max:2048'], 'b' => ['nullable', 'string', 'max:2048'], 'confidence' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'match_method' => ['nullable', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:5000'], 'evidence' => ['nullable', 'string', 'max:20000']]);

        return $this->attempt(fn () => match ($step) {
            'propose' => $sources->propose($user, $this->byIri($request, (string) ($data['a'] ?? '')), $this->byIri($request, (string) ($data['b'] ?? '')), $data),
            'accept', 'reject' => $sources->resolve($user, $resolution ?? abort(404), $step === 'accept', $data['notes'] ?? null),
            'withdraw' => $sources->withdraw($user, $resolution ?? abort(404)),
        }, __('Saved.'));
    }

    // Searching across fonds.

    public function across(Request $request): View
    {
        $user = $request->user();
        $sources = app(Sources::class);
        $results = null;
        $error = null;
        if ($request->filled('q')) {
            try {
                $results = $sources->across($user, (string) $request->query('q'), array_filter((array) $request->query('fonds', [])));
            } catch (InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }

        return view('analysis.across', ['fonds' => $sources->fonds($user), 'results' => $results, 'error' => $error, 'chosen' => (array) $request->query('fonds', [])]);
    }

    // Reports.

    public function reports(Request $request): View
    {
        $user = $request->user();

        return view('analysis.reports', ['reports' => app(Reports::class)->visible($user), 'projects' => $this->projects->contributing($user),
            'templates' => ResearchReportTemplate::where(fn ($w) => $w->whereNull('institution_id')->orWhere('institution_id', $user->institution_id))->with('templateType')->orderBy('name')->get(),
            'reviews' => ResearchPeerReview::where('reviewer_id', $user->id)->with('report', 'status')->latest('requested_at')->get()]);
    }

    public function createReport(Request $request): RedirectResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:500'], 'template_type' => ['required', 'string', 'max:64'], 'project_id' => ['nullable', 'uuid'],
            'description' => ['nullable', 'string', 'max:5000']]);
        try {
            $report = app(Reports::class)->create($request->user(), $data);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['research' => $e->getMessage()]);
        }

        return redirect()->route('research.reports.show', $report)->with('status', __('Saved.'));
    }

    public function report(Request $request, ResearchReport $report): View
    {
        $user = $request->user();
        $service = app(Reports::class);
        abort_unless($service->mayRead($user, $report), 404);

        return view($request->boolean('print') ? 'analysis.report-print' : 'analysis.report', ['report' => $report->load('sections.sectionType', 'sections.bibliography.citationStyle', 'sections.collection',
            'templateType', 'status', 'user', 'project', 'peerReviews.reviewer', 'peerReviews.status'), 'service' => $service, 'write' => $service->mayWrite($user, $report), 'user' => $user,
            'sectionTypes' => Concept::choices(Reports::SECTIONS)->get(), 'statuses' => Concept::choices(Vocab::BASE.'research-report-status/')->get(),
            'bibliographies' => Bibliography::where(fn ($w) => $w->where('user_id', $user->id)->when($report->project_id, fn ($q) => $q->orWhere('project_id', $report->project_id)))->orderBy('name')->get(),
            'collections' => ResearchCollection::where(fn ($w) => $w->where('user_id', $user->id)->when($report->project_id, fn ($q) => $q->orWhere('project_id', $report->project_id)))->orderBy('name')->get()]);
    }

    public function reportStep(Request $request, ResearchReport $report, string $step): RedirectResponse
    {
        $user = $request->user();
        $service = app(Reports::class);
        $data = $request->validate(['title' => ['nullable', 'string', 'max:500'], 'description' => ['nullable', 'string', 'max:5000'], 'status' => ['nullable', 'string', 'max:64'],
            'section_type' => ['nullable', 'string', 'max:64'], 'content' => ['nullable', 'string', 'max:100000'], 'bibliography_id' => ['nullable', 'uuid'], 'collection_id' => ['nullable', 'uuid'],
            'email' => ['nullable', 'email', 'max:255']]);

        return $this->attempt(fn () => match ($step) {
            'update' => $service->update($user, $report, $data),
            'section' => $service->saveSection($user, $report, $data),
            'fill' => $service->fill($user, $report),
            'review' => $service->requestReview($user, $report, (string) ($data['email'] ?? '')),
            'delete' => $service->delete($user, $report),
        }, __('Saved.'), $step === 'delete' ? ['research.reports.index'] : null);
    }

    public function sectionStep(Request $request, ResearchReportSection $section, string $step): RedirectResponse
    {
        $service = app(Reports::class);
        if ($step === 'update') {
            $data = $request->validate(['section_type' => ['nullable', 'string', 'max:64'], 'title' => ['nullable', 'string', 'max:500'], 'content' => ['nullable', 'string', 'max:100000'],
                'bibliography_id' => ['nullable', 'uuid'], 'collection_id' => ['nullable', 'uuid']]);

            return $this->attempt(fn () => $service->saveSection($request->user(), $section->report, $data, $section), __('Saved.'));
        }

        return $this->attempt(fn () => $service->sectionStep($request->user(), $section, $step), __('Saved.'));
    }

    public function peerReview(Request $request, ResearchPeerReview $review, string $step): RedirectResponse
    {
        $data = $request->validate(['feedback' => ['nullable', 'string', 'max:20000'], 'rating' => ['nullable', 'integer', 'min:1', 'max:5']]);

        return $this->attempt(fn () => app(Reports::class)->review($request->user(), $review, $step, $data), __('Saved.'));
    }

    // The use of the research services.

    public function analytics(Request $request): View
    {
        abort_unless($request->user()->hasPermission(Vocab::permission('manage-reading-room')), 403);
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $tz = config('workspace.timezone');
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'], $tz) : CarbonImmutable::now($tz);
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'], $tz) : $to->subDays(30);

        return view('analysis.analytics', ['from' => $from, 'to' => $to, 'figures' => app(Analytics::class)->dashboard($request->user(), $from, $to)]);
    }

    private function byIri(Request $request, string $iri): Model
    {
        foreach ([Agent::class, Repository::class, RecordResource::class, Activity::class, Place::class] as $class) {
            if ($found = $class::where(['institution_id' => $request->user()->institution_id, 'iri' => trim($iri)])->whereNull('deleted_at')->first()) {
                return $found;
            }
        }

        throw new InvalidArgumentException(__('Nothing you may read has that IRI.'));
    }

    private function read(Request $request, ResearchProject $project): int
    {
        $level = $this->projects->level($request->user(), $project);
        abort_unless($level > 0, 404);

        return $level;
    }

    private function attempt(callable $do, string $done, ?array $route = null): RedirectResponse
    {
        try {
            $do();
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['research' => $e->getMessage()]);
        } catch (\UnhandledMatchError) {
            abort(404);
        }

        return ($route ? redirect()->route(...$route) : back())->with('status', $done);
    }
}
