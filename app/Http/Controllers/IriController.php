<?php

// OpenRiC Workspace - persistent IRIs resolve: {base IRI}/{segment}/{UUID} (mapping section 5).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A unit of description, agent or repository answers with a 303 to its
// OpenRiC JSON-LD in the read API, or, for a browser of someone signed in,
// to its page. Activities, places and concepts are not yet in a profile the
// API declares, so their IRIs go to the page, behind sign-in.

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Agent;
use App\Models\Concept;
use App\Models\Place;
use App\Models\RecordResource;
use App\Models\Repository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class IriController extends Controller
{
    /** IRI segment => [model, its page, its API representation or null]. */
    private const SEGMENTS = [
        RecordResource::IRI_SEGMENT => [RecordResource::class, 'records.show', 'api.record'],
        Agent::IRI_SEGMENT => [Agent::class, 'agents.show', 'api.agent'],
        Repository::IRI_SEGMENT => [Repository::class, 'repositories.show', 'api.repository'],
        Activity::IRI_SEGMENT => [Activity::class, 'activities.show', null],
        Place::IRI_SEGMENT => [Place::class, 'places.show', null],
        Concept::IRI_SEGMENT => [Concept::class, 'concepts.show', null],
    ];

    public function resolve(Request $request, string $segment, string $id): RedirectResponse
    {
        [$class, $page, $api] = self::SEGMENTS[$segment] ?? abort(404);
        $entity = $class::where('iri', $request->url())->first() ?? $class::find($id) ?? abort(404);
        abort_unless($entity->getAttribute('iri') !== null && str_ends_with($entity->getAttribute('iri'), "/$segment/$id"), 404);
        $browser = in_array($request->getPreferredFormat(), ['html', null], true) && ! str_contains((string) $request->header('Accept'), 'json');
        $target = ($api === null || ($browser && $request->user())) ? route($page, $entity) : route($api, $entity->getKey());

        return redirect($target, 303)->header('Vary', 'Accept');
    }
}
