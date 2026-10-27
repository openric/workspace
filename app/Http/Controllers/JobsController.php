<?php

// OpenRiC Workspace - the background jobs screen (roadmap phase 5).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's JobsController (ahg-jobs): browse with statistics and filters, a job's
// detail, clearing old jobs and a CSV export. Each account sees its own jobs; one that may
// manage the institution sees all of them, clears and exports them.

namespace App\Http\Controllers;

use App\Jobs\JobsService;
use App\Models\BackgroundJob;
use App\Models\Concept;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JobsController extends Controller
{
    public function __construct(private JobsService $jobs) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $all = $this->managesAll($request);
        $statuses = Concept::choices(JobsService::STATUSES)->with('labels')->get();
        $types = Concept::choices(JobsService::TYPES)->with('labels')->get();
        $filters = ['status' => $statuses->pluck('notation')->contains($request->query('status')) ? $request->query('status') : null,
            'type' => $types->pluck('notation')->contains($request->query('type')) ? $request->query('type') : null,
            'sort' => $request->query('sort') === 'name' ? 'name' : 'date', 'user' => $all ? null : $user->id];

        return view('jobs.index', ['jobs' => $this->jobs->browse($user->institution_id, $filters), 'filters' => $filters, 'all' => $all,
            'stats' => $all ? $this->jobs->stats($user->institution_id) : null, 'statuses' => $statuses, 'types' => $types]);
    }

    public function show(Request $request, string $job): View
    {
        return view('jobs.show', ['job' => $this->visible($request, $job), 'all' => $this->managesAll($request)]);
    }

    public function cancel(Request $request, string $job): RedirectResponse
    {
        $found = $this->visible($request, $job);

        return redirect()->route('jobs.show', $found)->with('status', $this->jobs->cancel($found)
            ? __('Cancelled.') : __('Only a job that is still waiting can be cancelled.'));
    }

    public function clearInactive(Request $request): RedirectResponse
    {
        abort_unless($this->managesAll($request), 403);
        $count = $this->jobs->clearInactive($request->user()->institution_id, 30);

        return redirect()->route('jobs.index')->with('status', trans_choice(':count finished job older than 30 days cleared.|:count finished jobs older than 30 days cleared.', $count));
    }

    public function csv(Request $request): StreamedResponse
    {
        abort_unless($this->managesAll($request), 403);
        $lang = Vocab::languages();
        $jobs = $this->jobs->browse($request->user()->institution_id, [], 10000);

        return response()->streamDownload(function () use ($jobs, $lang) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'name', 'type', 'status', 'account', 'created', 'started', 'completed', 'duration_seconds', 'error'], escape: '');
            foreach ($jobs as $job) {
                fputcsv($out, [$job->id, $job->name, $job->type->label($lang), $job->status->label($lang), $job->user?->name, $job->created_at?->toIso8601String(),
                    $job->started_at?->toIso8601String(), $job->completed_at?->toIso8601String(), $job->duration, $job->error_message], escape: '');
            }
            fclose($out);
        }, 'jobs.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function managesAll(Request $request): bool
    {
        return $request->user()->can(Vocab::permission('manage-institution'));
    }

    /** Another institution's job, or another account's for one that may not see them all, does not exist here. */
    private function visible(Request $request, string $id): BackgroundJob
    {
        $job = $this->jobs->find($request->user()->institution_id, $id);
        abort_if($job === null || (! $this->managesAll($request) && $job->user_id !== $request->user()->id), 404);

        return $job;
    }
}
