<?php

// OpenRiC Workspace - the Dropdown manager (roadmap phase 20d), after Heratio's ahg-dropdown-manage DropdownController:
// every bundled vocabulary the forms offer, and, for one, its terms as the institution wants them: labelled in its own
// words in each language, ordered, with a default, and, where the vocabulary is open, with terms of its own and terms it
// hides. Who administers the institution manages it.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Models\Concept;
use App\Models\Vocabulary;
use App\Vocabulary\Dropdowns;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;

class DropdownController extends Controller
{
    public function __construct(private Dropdowns $dropdowns) {}

    public function index(Request $request): View
    {
        $q = mb_strtolower(trim((string) $request->query('q')));
        $vocabularies = $this->dropdowns->vocabularies()
            ->filter(fn (Vocabulary $v) => $q === '' || str_contains(mb_strtolower($v->title.' '.Dropdowns::name($v)), $q))->values();
        $changed = DB::table('vocabulary_overlays as o')->join('concepts as c', 'c.id', '=', 'o.concept_id')
            ->where('o.institution_id', $request->user()->institution_id)->selectRaw('c.vocabulary_id, count(*) as n')->groupBy('c.vocabulary_id')->pluck('n', 'vocabulary_id');
        $own = Concept::where('institution_id', $request->user()->institution_id)->whereNull('deprecated_at')
            ->whereIn('vocabulary_id', $vocabularies->pluck('id'))->selectRaw('vocabulary_id, count(*) as n')->groupBy('vocabulary_id')->pluck('n', 'vocabulary_id');

        return view('dropdowns.index', ['vocabularies' => $vocabularies, 'q' => $q, 'changed' => $changed, 'own' => $own, 'dropdowns' => $this->dropdowns]);
    }

    public function edit(Request $request, Vocabulary $vocabulary): View
    {
        $this->bundled($vocabulary);

        return view('dropdowns.edit', ['vocabulary' => $vocabulary, 'name' => Dropdowns::name($vocabulary), 'open' => $this->dropdowns->isOpen(Dropdowns::name($vocabulary)),
            'terms' => $this->dropdowns->terms($vocabulary, $request->user()->institution), 'languages' => array_values(array_unique(['en', 'af', app()->getLocale()]))]);
    }

    public function save(Request $request, Vocabulary $vocabulary): RedirectResponse
    {
        $this->bundled($vocabulary);
        $data = $request->validate(['terms' => ['array'], 'terms.*.labels' => ['array'], 'terms.*.labels.*' => ['nullable', 'string', 'max:255'],
            'terms.*.sort_order' => ['nullable', 'integer', 'between:0,100000'], 'terms.*.hidden' => ['nullable', 'boolean'], 'default' => ['nullable', 'uuid']]);
        $this->dropdowns->save($vocabulary, $request->user()->institution, $data['terms'] ?? [], $data['default'] ?? null, $request->user());

        return back()->with('status', __('Saved.'));
    }

    public function add(Request $request, Vocabulary $vocabulary): RedirectResponse
    {
        $this->bundled($vocabulary);
        $data = $request->validate(['label' => ['required', 'string', 'max:255'], 'language' => ['required', 'regex:/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/'],
            'notation' => ['nullable', 'string', 'max:100'], 'broader' => ['nullable', 'uuid']]);
        try {
            $this->dropdowns->add($vocabulary, $request->user()->institution, $data, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['label' => $e->getMessage()]);
        }

        return back()->with('status', __('Added.'));
    }

    public function remove(Request $request, Vocabulary $vocabulary, Concept $concept): RedirectResponse
    {
        $this->bundled($vocabulary);
        abort_unless($concept->vocabulary_id === $vocabulary->id, 404);
        try {
            $this->dropdowns->remove($concept, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['label' => $e->getMessage()]);
        }

        return back()->with('status', __('Removed from the list.'));
    }

    private function bundled(Vocabulary $vocabulary): void
    {
        abort_unless($vocabulary->institution_id === null && in_array(Dropdowns::name($vocabulary).'.ttl', (array) config('workspace.bundled_vocabularies'), true), 404);
    }
}
