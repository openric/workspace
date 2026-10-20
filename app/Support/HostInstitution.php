<?php

// OpenRiC Workspace - which institution a public request is for: the read API and the public catalogue alike.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The institution whose base IRI has the request's host, so every IRI a
// public page or the API gives resolves on the server that gave it; where
// one institution is installed, that one; otherwise none.

namespace App\Support;

use App\Models\Institution;
use Illuminate\Http\Request;

final class HostInstitution
{
    public static function for(Request $request): ?Institution
    {
        $host = $request->getHost();

        return Institution::all()->first(fn (Institution $i) => parse_url($i->base_iri, PHP_URL_HOST) === $host)
            ?? (Institution::count() === 1 ? Institution::first() : null);
    }
}
