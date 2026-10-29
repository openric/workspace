<?php

// OpenRiC Workspace - persistent IRIs resolve: {base IRI}/{segment}/{UUID} (mapping section 5).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A unit of description, agent or repository answers with a 303 to its
// OpenRiC JSON-LD in the read API, or, for a browser, to its public page,
// or its Workspace page for someone signed in. Activities, places and concepts are not yet in a profile the
// API declares, so their IRIs go to the page, behind sign-in; so do instantiations.

namespace App\Http\Controllers;

use App\Discovery\Links;
use App\Models\Accession;
use App\Models\Activity;
use App\Models\Agent;
use App\Models\Agreement;
use App\Models\Concept;
use App\Models\Instantiation;
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
        Instantiation::IRI_SEGMENT => [Instantiation::class, 'instantiations.show', null],
        // Staff only: the page asks for the accession permissions.
        Accession::IRI_SEGMENT => [Accession::class, 'accessions.show', null],
        Agreement::IRI_SEGMENT => [Agreement::class, 'agreements.show', null],
    ];

    public function resolve(Request $request, string $segment, string $id): RedirectResponse
    {
        [$class, $page, $api] = self::SEGMENTS[$segment] ?? abort(404);
        $entity = $class::where('iri', $request->url())->first() ?? $class::find($id) ?? abort(404);
        abort_unless($entity->getAttribute('iri') !== null && str_ends_with($entity->getAttribute('iri'), "/$segment/$id"), 404);
        $browser = in_array($request->getPreferredFormat(), ['html', null], true) && ! str_contains((string) $request->header('Accept'), 'json');
        // A browser: the page for someone signed in, else the public page; a client asking for data: the JSON-LD.
        $target = match (true) {
            $browser && $request->user() !== null, $api === null && ! $browser => route($page, $entity),
            $browser => Links::page($entity) ?? route($page, $entity),
            default => route($api, $entity->getKey()),
        };

        return redirect($target, 303)->header('Vary', 'Accept');
    }
}
