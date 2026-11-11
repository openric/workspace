<?php

// OpenRiC Workspace - full-text search for staff, and the search statistics (WS-016, roadmap phase 6, ADR 0009).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Models\Concept;
use App\Models\Repository;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Search\SearchAnalyticsService;
use App\Search\SearchIndex;
use App\Search\SearchService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class SearchController extends Controller
{
    private const PER_PAGE = 20;

    /** Search pages read past the asked one to fill it with what the account may read (#8). */
    private const MORE_PAGES = 5;

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
        $options = ['mode' => (string) $request->query('mode', 'words'), 'user' => $user, 'match' => (string) $request->query('match', 'words')];
        // A page of results the account may read (#8): the search's own pages are read in turn, the readable results of the
        // earlier pages skipped, until this page is full, so a result refused to the account no longer leaves a page short.
        // ponytail: page n reads the search's pages 1 to n again, and at most MORE_PAGES beyond them; where an account may
        // read few of what it finds, the upgrade is its rights in SQL, as PublicCatalogue::ids() decides the public's.
        $found = null;
        [$rows, $skip] = [collect(), ($page - 1) * self::PER_PAGE];
        for ($at = 1; $rows->count() < self::PER_PAGE && $at <= $page + self::MORE_PAGES; $at++) {
            $batch = $search->search($user->institution, $query, $public, $filters, $at, self::PER_PAGE, [...$options, 'record' => $at === 1]);
            $found ??= $batch;
            foreach ($this->readable($batch['results'], $user) as $row) {
                if ($skip > 0) {
                    $skip--;
                } elseif ($rows->count() < self::PER_PAGE) {
                    $rows->push($row);
                }
            }
            if (count($batch['results']) < self::PER_PAGE) {
                break; // the search's last page
            }
        }
        $found['results'] = $rows->values();

        return view('search.index', ['query' => $query, 'kind' => $kind, 'filters' => $filters, 'page' => $page, 'search' => $found, 'perPage' => self::PER_PAGE,
            'drafts' => ! $public, 'levels' => Concept::whereIn('id', array_keys($found['facets']['level']))->with('labels')->get()->keyBy('id'),
            'repositories' => Repository::whereIn('id', array_keys($found['facets']['repository']))->with('names')->get()->keyBy('id')]);
    }

    /**
     * The rows of a search page with their entities, those the account may read, in the search's order.
     *
     * @return Collection<int, array>
     */
    private function readable(array $rows, User $user): Collection
    {
        $entities = collect($rows)->groupBy('table')
            ->flatMap(fn ($group, $table) => collect(SearchIndex::KINDS)->first(fn ($c) => (new $c)->getTable() === $table)::whereIn('id', $group->pluck('id'))->get())
            ->keyBy('id');

        return collect($rows)->map(fn ($row) => $row + ['entity' => $entities->get($row['id'])])
            ->filter(fn ($row) => $row['entity'] !== null && EntityKinds::readable($user, $row['entity']))->values();
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
