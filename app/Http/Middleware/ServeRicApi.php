<?php

// OpenRiC Workspace - the read API: which institution it serves, and the headers every response carries.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The API serves the institution whose base IRI has the request's host, so
// every @id it gives resolves on the server that gave it; where one
// institution is installed, it serves that one. Reads are open to anyone
// (Core Discovery 2.1), as the institution's Public role may read; responses
// say they vary by Accept and allow any origin.

namespace App\Http\Middleware;

use App\Api\Problem;
use App\Models\Institution;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ServeRicApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();
        $institution = Institution::all()->first(fn (Institution $i) => parse_url($i->base_iri, PHP_URL_HOST) === $host)
            ?? (Institution::count() === 1 ? Institution::first() : null);
        if ($institution === null) {
            return Problem::response($request, 404, "No institution is published at $host.");
        }
        $request->attributes->set('institution', $institution);
        $response = $next($request);
        $response->headers->set('Vary', 'Accept');
        $response->headers->set('Access-Control-Allow-Origin', '*');
        if ($response->headers->get('Content-Type') === 'application/json' && ! str_contains((string) $request->header('Accept'), 'application/json')) {
            $response->headers->set('Content-Type', 'application/ld+json');
        }

        return $response;
    }
}
