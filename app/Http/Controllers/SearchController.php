<?php

// OpenRiC Workspace - full-text search for staff, and the search statistics (WS-016, roadmap phase 6, ADR 0009).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Models\Concept;
use App\Models\Repository;
use App\Relations\EntityKinds;
use App\Search\SearchAnalyticsService;
use App\Search\SearchIndex;
use App\Search\SearchService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SearchController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request, SearchService $search): View
    {
        $user = $request->user();
        $query = trim((string) $request->query('q', ''));
        $kind = array_key_exists((string) $request->query('kind'), SearchIndex::KINDS) ? (string) $request->query('kind') : null;
        $uuid = fn ($v) => is_string($v) && preg_match('/^[0-9a-f-]{36}$/', $v) ? $v : null;
        $year = fn ($v) => is_string($v) && preg_match('/^\d{1,4}$/', $v) ? (int) $v : null;
        $filters = ['kind' => $kind, 'level' => $uuid($request->query('level')), 'repository' => $uuid($request->query('repository')),
            'from' => $year($request->query('from')), 'to' => $year($request->query('to'))];
        $page = max(1, (int) $request->query('page', 1));
        // Drafts only for an account that may see drafts somewhere; each result is then checked against what it may read.
        $public = ! $user->mayAnywhere('view-draft', 'description');
        $found = $search->search($user->institution, $query, $public, $filters, $page, self::PER_PAGE);
        // ponytail: results the account may not read are dropped after paging, so a page can be short; filter in SQL when per-record denials are common.
        $results = collect($found['results'])->groupBy('table')
            ->flatMap(fn ($rows, $table) => collect(SearchIndex::KINDS)->first(fn ($c) => (new $c)->getTable() === $table)::whereIn('id', $rows->pluck('id'))->get())
            ->keyBy('id');
        $found['results'] = collect($found['results'])->map(fn ($row) => $row + ['entity' => $results->get($row['id'])])
            ->filter(fn ($row) => $row['entity'] !== null && EntityKinds::readable($user, $row['entity']))->values();

        return view('search.index', ['query' => $query, 'kind' => $kind, 'filters' => $filters, 'page' => $page, 'search' => $found, 'perPage' => self::PER_PAGE,
            'drafts' => ! $public, 'levels' => Concept::whereIn('id', array_keys($found['facets']['level']))->with('labels')->get()->keyBy('id'),
            'repositories' => Repository::whereIn('id', array_keys($found['facets']['repository']))->with('names')->get()->keyBy('id')]);
    }

    public function statistics(Request $request, SearchAnalyticsService $analytics): View
    {
        $days = in_array((int) $request->query('days', 30), [7, 30, 90, 365], true) ? (int) $request->query('days', 30) : 30;
        $since = CarbonImmutable::now()->subDays($days);
        $institution = $request->user()->institution_id;

        return view('search.statistics', ['days' => $days, 'totals' => $analytics->totals($institution, $since),
            'top' => $analytics->topQueries($institution, $since), 'zero' => $analytics->zeroResultQueries($institution, $since)]);
    }
}
