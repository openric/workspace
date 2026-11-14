<?php

// OpenRiC Workspace - a page sent to a signed-in account is never kept by the browser (Cache-Control: no-store), so after a
// timeout or Log out the Back button cannot show it again, which matters on a shared reading-room computer (found by
// Dr Johan Pieterse, 13 November 2026). Only HTML pages: a file or an export keeps the caching its controller set.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NoStoreWhenSignedIn
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->user() !== null && str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
