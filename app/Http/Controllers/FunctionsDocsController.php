<?php

// OpenRiC Workspace - the code reference (roadmap phase 20d), after Heratio's ahg-functions-docs FunctionsDocsController:
// the catalogues, and one of them filtered and a page of its groups at a time. Who administers the institution reads it.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Help\FunctionsDocs;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

class FunctionsDocsController extends Controller
{
    public function __construct(private FunctionsDocs $docs) {}

    public function index(): View
    {
        return view('help.functions', ['catalogues' => $this->docs->index()]);
    }

    public function show(Request $request, string $kind): View
    {
        abort_unless(isset(FunctionsDocs::KINDS[$kind]), 404);
        $q = mb_strtolower(trim((string) $request->query('q')));
        $groups = collect($this->docs->catalogue($kind))
            ->map(fn ($entries, $group) => $q === '' || str_contains(mb_strtolower($group), $q) ? $entries
                : array_values(array_filter($entries, fn ($e) => str_contains(mb_strtolower(implode(' ', $e)), $q))))
            ->filter();
        $perPage = FunctionsDocs::KINDS[$kind]['per_page'];
        $page = max(1, (int) $request->query('page', 1));
        $paginator = new LengthAwarePaginator($groups->slice(($page - 1) * $perPage, $perPage, true), $groups->count(), $perPage, $page,
            ['path' => $request->url(), 'query' => $request->query()]);

        return view('help.functions-kind', ['kind' => $kind, 'meta' => FunctionsDocs::KINDS[$kind], 'groups' => $paginator, 'q' => $q,
            'entries' => $groups->sum(fn ($e) => count($e))]);
    }
}
