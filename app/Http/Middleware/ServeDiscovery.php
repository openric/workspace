<?php

// OpenRiC Workspace - the public catalogue: which institution it shows (WS-009).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Middleware;

use App\Api\PublicCatalogue;
use App\Support\HostInstitution;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ServeDiscovery
{
    public function handle(Request $request, Closure $next): Response
    {
        $institution = HostInstitution::for($request) ?? abort(404);
        $request->attributes->set('institution', $institution);
        $request->attributes->set('catalogue', new PublicCatalogue($institution));

        return $next($request);
    }
}
