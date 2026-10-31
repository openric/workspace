<?php

// OpenRiC Workspace - curated IIIF collections for staff (roadmap phase 14a, ADR 0014), after Heratio's
// IiifCollectionController (index, create, store, edit, update, destroy, addItems, removeItem, reorder). Who may publish
// descriptions may curate them: a curated collection presents published records to the public.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Iiif\CuratedCollections;
use App\Models\Concept;
use App\Models\IiifCollection;
use App\Models\IiifCollectionItem;
use App\Models\Institution;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class IiifCollectionController extends Controller
{
    public function __construct(private CuratedCollections $collections) {}

    public function index(Request $request): View
    {
        $this->authorise($request);

        return view('iiif.index', ['collections' => IiifCollection::where('institution_id', $request->user()->institution_id)->withCount('items')
            ->with('parent')->orderBy('position')->orderBy('label')->get(), 'behaviors' => Concept::choices(CuratedCollections::BEHAVIORS)->get()]);
    }

    public function show(Request $request, IiifCollection $collection): View
    {
        $this->own($request, $collection);

        return view('iiif.show', ['collection' => $collection, 'items' => $collection->items()->with('record', 'memberCollection', 'resourceType')->get(),
            'behaviors' => Concept::choices(CuratedCollections::BEHAVIORS)->get(), 'types' => Concept::choices(CuratedCollections::RESOURCE_TYPES)->get(),
            'others' => IiifCollection::where('institution_id', $collection->institution_id)->where('id', '!=', $collection->id)->orderBy('label')->get()]);
    }

    public function save(Request $request, ?IiifCollection $collection = null): RedirectResponse
    {
        $collection ? $this->own($request, $collection) : $this->authorise($request);
        $data = $request->validate(['label' => ['required', 'string', 'max:255'], 'summary' => ['nullable', 'string', 'max:5000'], 'language' => ['nullable', 'string', 'max:35'],
            'attribution' => ['nullable', 'string', 'max:500'], 'behavior' => ['nullable', 'string'], 'nav_date' => ['nullable', 'date'], 'is_public' => ['nullable', 'boolean'],
            'parent_id' => ['nullable', 'uuid']]);
        try {
            $saved = $this->collections->save(Institution::findOrFail($request->user()->institution_id), $data, $collection, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['collection' => $e->getMessage()]);
        }

        return redirect()->route('iiif-collections.show', $saved)->with('status', __('Saved.'));
    }

    public function destroy(Request $request, IiifCollection $collection): RedirectResponse
    {
        $this->own($request, $collection);
        $this->collections->delete($collection, $request->user());

        return redirect()->route('iiif-collections.index')->with('status', __('Deleted: :name.', ['name' => $collection->label]));
    }

    public function add(Request $request, IiifCollection $collection): RedirectResponse
    {
        $this->own($request, $collection);
        $data = $request->validate(['target' => ['nullable', 'string', 'max:2000'], 'type' => ['nullable', 'string'], 'member' => ['nullable', 'uuid'], 'label' => ['nullable', 'string', 'max:500']]);
        try {
            $this->collections->add($collection, $data, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['item' => $e->getMessage()]);
        }

        return back()->with('status', __('Added.'));
    }

    public function move(Request $request, IiifCollection $collection, IiifCollectionItem $item, string $direction): RedirectResponse
    {
        $this->own($request, $collection, $item);
        $this->collections->move($item, $direction === 'up' ? -1 : 1, $request->user());

        return back();
    }

    public function remove(Request $request, IiifCollection $collection, IiifCollectionItem $item): RedirectResponse
    {
        $this->own($request, $collection, $item);
        $this->collections->remove($item, $request->user());

        return back()->with('status', __('Removed.'));
    }

    private function authorise(Request $request): void
    {
        abort_unless($request->user()->mayAnywhere('publish', 'description'), 403);
    }

    private function own(Request $request, IiifCollection $collection, ?IiifCollectionItem $item = null): void
    {
        $this->authorise($request);
        abort_unless($collection->institution_id === $request->user()->institution_id && ($item === null || $item->iiif_collection_id === $collection->id), 404);
    }
}
