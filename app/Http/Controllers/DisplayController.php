<?php

// OpenRiC Workspace - display profiles and browse settings (roadmap phase 20d), after Heratio's ahg-display
// DisplayController (profiles, levels, fields and browse settings): the institution's profiles, each for a level of
// description or for every level, and how its staff browse. Who administers the institution sets them.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Description\Elements;
use App\Display\Displays;
use App\Models\Concept;
use App\Models\DisplayProfile;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class DisplayController extends Controller
{
    public function __construct(private Displays $displays) {}

    public function index(Request $request): View
    {
        return view('display.index', ['profiles' => DisplayProfile::where('institution_id', $request->user()->institution_id)->with('level.labels', 'actions.labels')
            ->orderByRaw('level_id IS NOT NULL')->orderBy('name')->get(), 'browse' => $this->displays->institutionBrowse($request->user()->institution_id),
            'views' => Concept::choices(Vocab::BASE.'browse-view/')->get(), 'sorts' => Concept::choices(Vocab::BASE.'browse-sort/')->get(),
            'directions' => Concept::choices(Vocab::BASE.'sort-direction/')->get()]);
    }

    public function edit(Request $request, ?DisplayProfile $profile = null): View
    {
        $profile && $this->own($request, $profile);

        return view('display.edit', ['profile' => $profile, 'areas' => collect(Elements::byArea(Elements::ISAD_G)), 'levels' => Concept::choices(Vocab::BASE.'level-of-description/')->get(),
            'sizes' => Concept::choices(Vocab::BASE.'thumbnail-size/')->get(), 'actions' => Concept::choices(Vocab::BASE.'display-action/')->get(),
            'languages' => array_values(array_unique(['en', 'af', app()->getLocale()]))]);
    }

    public function save(Request $request, ?DisplayProfile $profile = null): RedirectResponse
    {
        $profile && $this->own($request, $profile);
        $data = $request->validate(['name' => ['required', 'string', 'max:200'], 'level' => ['nullable', 'string', 'max:100'], 'elements' => ['nullable', 'array'], 'elements.*' => ['string'],
            'order' => ['nullable', 'array'], 'order.*' => ['nullable', 'integer', 'between:0,1000'], 'labels' => ['nullable', 'array'], 'show_tree' => ['nullable', 'boolean'],
            'thumbnail_size' => ['nullable', Rule::in(Vocab::notations('thumbnail-size'))], 'actions' => ['nullable', 'array'], 'actions.*' => ['string']]);
        // The ticked elements, in the order given (unnumbered ones after, in the standard's order).
        $order = (array) ($data['order'] ?? []);
        $data['elements'] = collect($data['elements'] ?? [])->values()->sortBy(fn ($n, $i) => [is_numeric($order[$n] ?? null) ? (int) $order[$n] : PHP_INT_MAX, $i])->values()->all();
        try {
            $profile = $this->displays->saveProfile($request->user()->institution, $profile, $data, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['level' => $e->getMessage()]);
        }

        return redirect()->route('display.edit', $profile)->with('status', __('Saved.'));
    }

    public function destroy(Request $request, DisplayProfile $profile): RedirectResponse
    {
        $this->own($request, $profile);
        $this->displays->deleteProfile($profile, $request->user());

        return redirect()->route('display.index')->with('status', __('Deleted.'));
    }

    public function saveBrowse(Request $request): RedirectResponse
    {
        $data = $request->validate(['view' => ['required', Rule::in(Vocab::notations('browse-view'))], 'sort' => ['required', Rule::in(Vocab::notations('browse-sort'))],
            'direction' => ['required', Rule::in(Vocab::notations('sort-direction'))], 'per_page' => ['required', 'integer', 'between:10,200'],
            'show_thumbnails' => ['nullable', 'boolean'], 'allow_user_override' => ['nullable', 'boolean']]);
        $this->displays->saveBrowse($request->user()->institution, null, $data, $request->user());

        return back()->with('status', __('Saved.'));
    }

    private function own(Request $request, DisplayProfile $profile): void
    {
        abort_unless($profile->institution_id === $request->user()->institution_id, 404);
    }
}
