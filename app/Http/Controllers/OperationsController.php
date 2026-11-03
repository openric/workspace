<?php

// OpenRiC Workspace - operations (roadmap phase 12a): how the installation is doing, the background jobs that failed,
// fixity schedules, runs and failures, and /metrics for a monitor. The institution's own fixity is for who may manage it;
// the installation as a whole only for its operators (App\Operations\Health::operator).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Fixity\Fixity;
use App\Models\Concept;
use App\Models\FixityFailure;
use App\Models\FixityRun;
use App\Models\FixitySchedule;
use App\Models\Repository;
use App\Models\User;
use App\Operations\Health;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\IpUtils;

class OperationsController extends Controller
{
    public function __construct(private Health $health, private Fixity $fixity) {}

    public function index(Request $request): View
    {
        $operator = Health::operator($request->user());
        $figures = $operator ? $this->health->figures() : null;
        $institution = $request->user()->institution_id;

        return view('operations.index', ['operator' => $operator, 'figures' => $figures, 'problems' => $figures ? $this->health->problems($figures) : [],
            'failedJobs' => $operator ? DB::table('failed_jobs')->orderByDesc('failed_at')->limit(50)->get() : collect(),
            'openFailures' => FixityFailure::where('institution_id', $institution)->whereHas('status', fn ($q) => $q->whereIn('notation', FixityFailure::OPEN))->count(),
            'runs' => FixityRun::where('institution_id', $institution)->with('schedule', 'status.labels', 'trigger.labels')->orderByDesc('started_at')->limit(10)->get(),
            'metricsOn' => config('workspace.metrics_token') !== '']);
    }

    /** Try a failed background job again, or forget it (Laravel's queue:retry and queue:forget). Operators only. */
    public function failedJob(Request $request, string $uuid, string $action): RedirectResponse
    {
        abort_unless(Health::operator($request->user()), 403);
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);
        Artisan::call($action === 'retry' ? 'queue:retry' : 'queue:forget', ['id' => $action === 'retry' ? [$uuid] : $uuid]);

        return redirect()->route('operations.index')->with('status', $action === 'retry' ? __('The job will be tried again.') : __('The job was forgotten.'));
    }

    /** Prometheus text, for a monitor that sends the token; not served at all without one. */
    public function metrics(Request $request): Response
    {
        $token = (string) config('workspace.metrics_token');
        abort_if($token === '', 404);
        $ips = array_filter(array_map('trim', explode(',', (string) config('workspace.metrics_ips'))));
        abort_unless($ips === [] || IpUtils::checkIp((string) $request->ip(), $ips), 403);
        abort_unless(hash_equals($token, (string) $request->bearerToken()), 401);

        return response($this->health->prometheus(), 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    public function schedules(Request $request): View
    {
        $institution = $request->user()->institution_id;

        return view('operations.fixity', ['schedules' => FixitySchedule::where('institution_id', $institution)->with('scope.labels', 'frequency.labels', 'repository', 'record', 'notifyUser')->orderBy('name')->get(),
            'scopes' => $this->choices('fixity-scope'), 'frequencies' => $this->choices('fixity-frequency'),
            'repositories' => Repository::where('institution_id', $institution)->whereNull('deleted_at')->get()->sortBy(fn ($r) => EntityKinds::label($r)),
            'accounts' => User::where('institution_id', $institution)->orderBy('name')->get(),
            'runs' => FixityRun::where('institution_id', $institution)->with('schedule', 'status.labels', 'trigger.labels', 'user')->orderByDesc('started_at')->limit(50)->get()]);
    }

    public function saveSchedule(Request $request, ?FixitySchedule $schedule = null): RedirectResponse
    {
        abort_if($schedule && $schedule->institution_id !== $request->user()->institution_id, 404);
        try {
            $this->fixity->saveSchedule($request->user()->institution, $request->all(), $request->user(), $schedule);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['fixity' => $e->getMessage()]);
        }

        return redirect()->route('fixity.schedules')->with('status', __('Saved.'));
    }

    /** Run a schedule now, in the background, as the scheduler would. */
    public function runNow(Request $request, FixitySchedule $schedule): RedirectResponse
    {
        abort_unless($schedule->institution_id === $request->user()->institution_id, 404);
        $user = $request->user();
        dispatch(fn () => app(Fixity::class)->run($user->institution, $schedule->fresh(), 'manual', $user));

        return redirect()->route('fixity.schedules')->with('status', __('The run has started; its results appear below when it ends.'));
    }

    public function run(Request $request, FixityRun $run): View
    {
        abort_unless($run->institution_id === $request->user()->institution_id, 404);

        return view('operations.run', ['run' => $run->load('schedule', 'status.labels', 'trigger.labels', 'user'),
            'checks' => $run->checks()->with('outcome.labels', 'file.instantiation.record', 'file.use')->orderByRaw('outcome_id = ? DESC', [Concept::where('iri', Vocab::BASE.'fixity-outcome/pass')->value('id')])->orderBy('checked_at')->paginate(100)]);
    }

    public function failures(Request $request): View
    {
        $status = (string) $request->query('status', 'open');

        return view('operations.failures', ['status' => $status, 'statuses' => $this->choices('fixity-failure-status'),
            'failures' => FixityFailure::where('institution_id', $request->user()->institution_id)
                ->when($status === 'open', fn ($q) => $q->whereHas('status', fn ($s) => $s->whereIn('notation', FixityFailure::OPEN)), fn ($q) => $q->whereHas('status', fn ($s) => $s->where('notation', $status)))
                ->with('outcome.labels', 'status.labels', 'file.instantiation.record', 'file.use', 'acknowledgedBy')->orderByDesc('last_failed_at')->get()]);
    }

    public function updateFailure(Request $request, FixityFailure $failure): RedirectResponse
    {
        abort_unless($failure->institution_id === $request->user()->institution_id, 404);
        try {
            $this->fixity->updateFailure($failure, (string) $request->input('status'), $request->input('notes'), $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['fixity' => $e->getMessage()]);
        }

        return redirect()->route('fixity.failures')->with('status', __('Saved.'));
    }

    private function choices(string $vocabulary)
    {
        return Concept::choices(Vocab::BASE.$vocabulary.'/')->get();
    }
}
