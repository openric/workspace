<?php

// OpenRiC Workspace - an address of an experimental module the installation does not offer answers 404, as if it did not
// exist (App\Support\Modules, MODULES.md).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Middleware;

use App\Support\Modules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireModule
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Modules::routeOn($request->route()?->getName()), 404);

        return $next($request);
    }
}
