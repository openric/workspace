<?php

// OpenRiC Workspace - the staff dashboard (phase 26): the work waiting for the account rather than a list of links. Its open
// tasks, the proposals waiting for review, the institution's descriptions, the installation's health for those who run it,
// the background jobs, and the descriptions most recently changed that it may read.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Description\WorkflowService;
use App\Models\BackgroundJob;
use App\Models\Proposal;
use App\Models\RecordResource;
use App\Operations\Health;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends Controller
{
    /** Descriptions shown under recent changes. */
    private const RECENT = 6;

    public function __invoke(Request $request, WorkflowService $workflows, Health $health): View|RedirectResponse
    {
        // A visitor who is not signed in has the public catalogue.
        if ($request->user() === null) {
            return redirect()->route('discover.home');
        }
        $user = $request->user()->load('institution', 'roles.labels');
        $institution = $user->institution_id;
        $reviews = $user->mayAnywhere('review-proposals', 'description');
        $proposals = $reviews ? Proposal::where('institution_id', $institution)->whereHas('status', fn ($q) => $q->where('iri', Vocab::ASSERTION_STATUSES.'proposed')) : null;
        $operator = Health::operator($user);
        $recent = RecordResource::where('institution_id', $institution)->whereNull('deleted_at')->with('names.nameType', 'names.state', 'level.labels')
            ->orderByDesc('updated_at')->limit(self::RECENT * 3)->get()
            ->filter(fn (RecordResource $r) => $user->may('read', 'description', $r))->take(self::RECENT)->values();

        return view('home', [
            'user' => $user,
            'tasks' => $workflows->mine($user)->count(),
            'proposalCount' => $proposals?->count(),
            'proposals' => $proposals?->with('kind', 'record')->orderByDesc('created_at')->limit(4)->get() ?? collect(),
            'descriptions' => $user->mayAnywhere('read', 'description') ? RecordResource::where('institution_id', $institution)->whereNull('deleted_at')->count() : null,
            'problems' => $operator ? $health->problems($health->figures()) : null,
            'jobs' => BackgroundJob::where('institution_id', $institution)->whereHas('status', fn ($q) => $q->whereIn('notation', ['pending', 'running']))->count(),
            'recent' => $recent,
        ]);
    }
}
