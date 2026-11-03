<?php

// OpenRiC Workspace - duplicate archival descriptions: the queue, two compared side by side, dismissing, confirming
// and merging, the rules and a scan (roadmap phase 22b), after Heratio's ahg-dedupe DedupeController.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Dedupe\Duplicates;
use App\Models\Concept;
use App\Models\DuplicateDetection;
use App\Models\DuplicateRule;
use App\Models\Repository;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use App\Versions\Versions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class DuplicateController extends Controller
{
    public function __construct(private Duplicates $duplicates) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $status = $request->query('status', 'pending');
        $pairs = DuplicateDetection::where('institution_id', $user->institution_id)->whereHas('status', fn ($q) => $q->where('notation', $status))
            ->with('recordA', 'recordB', 'ruleType', 'status', 'reviewedBy')->orderByDesc('similarity_score')->limit(200)->get()
            ->filter(fn ($p) => EntityKinds::readable($user, $p->recordA) && EntityKinds::readable($user, $p->recordB));

        return view('duplicates.index', ['pairs' => $pairs, 'status' => $status, 'rules' => $this->duplicates->rules($user->institution_id),
            'ruleTypes' => Concept::choices(Vocab::BASE.'duplicate-rule-type/')->get(), 'statuses' => Concept::choices(Vocab::BASE.'duplicate-status/')->get(),
            'repositories' => Repository::where('institution_id', $user->institution_id)->whereNull('deleted_at')->get()]);
    }

    public function show(Request $request, DuplicateDetection $pair): View
    {
        $this->own($request, $pair);
        $versions = app(Versions::class);

        return view('duplicates.show', ['pair' => $pair->load('recordA', 'recordB', 'ruleType', 'status'), 'a' => $versions->snapshot($pair->recordA),
            'b' => $versions->snapshot($pair->recordB)]);
    }

    public function step(Request $request, DuplicateDetection $pair, string $step): RedirectResponse
    {
        $this->own($request, $pair);
        try {
            if ($step === 'merge') {
                $moved = $this->duplicates->merge($request->user(), $pair, (string) $request->input('kept'));

                return redirect()->route('records.show', $pair->fresh()->kept_id)->with('status', __('Merged: :units units, :instantiations instantiations, :relations relations, :names names, :values values and :dates dates moved into it.', $moved));
            }
            $this->duplicates->decide($request->user(), $pair, $step === 'dismiss' ? 'dismissed' : 'confirmed', $request->input('notes'));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['duplicates' => $e->getMessage()]);
        }

        return redirect()->route('duplicates.index')->with('status', __('Saved.'));
    }

    public function scan(Request $request): RedirectResponse
    {
        $found = $this->duplicates->scan($request->user()->institution_id);

        return back()->with('status', trans_choice(':count pair found that was not found before.|:count pairs found that were not found before.', $found, ['count' => $found]));
    }

    public function saveRule(Request $request, ?DuplicateRule $rule = null): RedirectResponse
    {
        abort_if($rule && $rule->institution_id !== $request->user()->institution_id, 404);
        try {
            $this->duplicates->saveRule($request->user()->institution_id, $request->all(), $rule);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['duplicates' => $e->getMessage()]);
        }

        return back()->with('status', __('Saved.'));
    }

    private function own(Request $request, DuplicateDetection $pair): void
    {
        abort_unless($pair->institution_id === $request->user()->institution_id, 404);
        abort_unless(EntityKinds::readable($request->user(), $pair->recordA) && EntityKinds::readable($request->user(), $pair->recordB), 404);
    }
}
