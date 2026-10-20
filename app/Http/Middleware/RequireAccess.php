<?php

// OpenRiC Workspace - let a request through only if the account holds the action
// on the kind of record somewhere (ADR 0005); the controller then checks the record.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAccess
{
    /** Usage: ->middleware('access:read,description') */
    public function handle(Request $request, Closure $next, string $action, string $kind): Response
    {
        abort_unless($request->user()?->mayAnywhere($action, $kind), 403);

        return $next($request);
    }
}
