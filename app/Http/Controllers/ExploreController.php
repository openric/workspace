<?php

// OpenRiC Workspace - ways into the public catalogue (roadmap phase 17d1), after Heratio's ExploreCollectionController,
// ThemesController, PlacesController, PeopleController, GenresController and TimelineController: the hub with a few of
// each, each way in in full, the published units one leads to, and the collection over time.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Api\PublicCatalogue;
use App\Discovery\Explore;
use App\Models\Agent;
use App\Models\Concept;
use App\Models\Place;
use App\Support\Vocab;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class ExploreController extends Controller
{
    public function hub(Request $request): View
    {
        $catalogue = $this->catalogue($request);
        $explore = new Explore($catalogue->institution);
        $facets = collect(array_keys(Explore::FACETS))->mapWithKeys(fn ($f) => [$f => $this->named($f, $explore->top($f, 8), $catalogue)]);

        return view('discover.ways-in', ['view' => $catalogue->view, 'facets' => $facets, 'timeline' => $explore->timeline()]);
    }

    public function facet(Request $request, string $facet): View
    {
        $catalogue = $this->catalogue($request);

        return view('discover.way-in', ['view' => $catalogue->view, 'facet' => $facet, 'items' => $this->named($facet, (new Explore($catalogue->institution))->top($facet, 200), $catalogue)]);
    }

    public function units(Request $request, string $facet, string $id): View
    {
        $catalogue = $this->catalogue($request);
        $named = $this->named($facet, collect([(object) ['id' => $id, 'n' => 0]]), $catalogue)->first() ?? abort(404);

        return view('discover.way-in-units', ['view' => $catalogue->view, 'facet' => $facet, 'item' => $named, 'units' => (new Explore($catalogue->institution))->units($facet, $id)]);
    }

    public function timeline(Request $request): View
    {
        $catalogue = $this->catalogue($request);

        return view('discover.timeline', ['view' => $catalogue->view, 'timeline' => (new Explore($catalogue->institution))->timeline()]);
    }

    public function decade(Request $request, int $from): View
    {
        $catalogue = $this->catalogue($request);
        $from = intdiv($from, 10) * 10;

        return view('discover.way-in-units', ['view' => $catalogue->view, 'facet' => null, 'item' => ['name' => __(':from to :to', ['from' => $from, 'to' => $from + 9]), 'url' => null],
            'units' => (new Explore($catalogue->institution))->decade($from)]);
    }

    /** Each way in with its name and, for agents and places, its public page. @return Collection<int, array{id: string, name: string, n: int, url: ?string}> */
    private function named(string $facet, Collection $rows, PublicCatalogue $catalogue): Collection
    {
        $table = Explore::FACETS[$facet]['table'];
        $entities = (match ($table) {
            'concepts' => Concept::whereIn('id', $rows->pluck('id'))->with('labels')->get(),
            'agents' => Agent::whereIn('id', $rows->pluck('id'))->get()->filter(fn ($a) => $catalogue->view->readable($a)),
            default => Place::whereIn('id', $rows->pluck('id'))->get()->filter(fn ($p) => $catalogue->view->readable($p)),
        })->keyBy('id');

        return $rows->filter(fn ($r) => $entities->has($r->id))->map(fn ($r) => ['id' => $r->id, 'n' => (int) $r->n, 'name' => $table === 'concepts'
            ? (string) $entities[$r->id]->label(Vocab::languages()) : (string) $catalogue->view->label($entities[$r->id]),
            'url' => match ($table) {
                'agents' => route('discover.agent', $r->id), 'places' => route('discover.place', $r->id), default => null
            }])->values();
    }

    private function catalogue(Request $request): PublicCatalogue
    {
        return $request->attributes->get('catalogue');
    }
}
