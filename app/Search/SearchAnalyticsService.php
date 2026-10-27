<?php

/**
 * SearchAnalyticsService - Heratio
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * This file is part of Heratio.
 *
 * Heratio is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * OpenRiC Workspace - copied from Heratio, packages/ahg-search/src/Services/SearchAnalyticsService.php at commit
 * 4131171e4 (heratio-dev; governance/CODE_PROVENANCE.md, roadmap phase 6): recordQuery, topQueries,
 * zeroResultQueries and totals. Changed: the Workspace's table search_queries, per institution and public or staff;
 * nothing about who searched is kept (no account, no anonymised address hash); click tracking (recordClick, the
 * click columns) is not copied.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Search;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SearchAnalyticsService
{
    /** Longer queries are cut; their first characters are what the aggregates use. */
    protected const QUERY_MAX = 512;

    /**
     * Persist a query execution. Safe to call from the request hot path: any failure is swallowed so a
     * logging hiccup never breaks the search response.
     */
    public function recordQuery(string $institutionId, bool $public, string $query, array $filters, int $resultCount, int|float $responseMs): ?int
    {
        $query = trim($query);
        if ($query === '') {
            return null;
        }
        try {
            return (int) DB::table('search_queries')->insertGetId([
                'institution_id' => $institutionId,
                'public' => $public,
                'query' => mb_substr($query, 0, self::QUERY_MAX),
                'filters' => array_filter($filters) === [] ? null : json_encode(array_filter($filters), JSON_UNESCAPED_UNICODE),
                'result_count' => max(0, $resultCount),
                'response_ms' => max(0, (int) $responseMs),
            ]);
        } catch (\Throwable $e) {
            Log::debug('SearchAnalyticsService::recordQuery failed: '.$e->getMessage());

            return null;
        }
    }

    /** Top queries by execution count since $since: query, count, average results, last seen. */
    public function topQueries(string $institutionId, CarbonImmutable $since, int $limit = 20): array
    {
        return DB::table('search_queries')->where('institution_id', $institutionId)->where('executed_at', '>=', $since)
            ->select('query', DB::raw('COUNT(*) as count'), DB::raw('AVG(result_count) as avg_results'), DB::raw('MAX(executed_at) as last_seen'))
            ->groupBy('query')->orderByDesc('count')->limit($limit)->get()
            ->map(fn ($r) => ['query' => $r->query, 'count' => (int) $r->count, 'avg_results' => round((float) $r->avg_results, 1), 'last_seen' => $r->last_seen])->toArray();
    }

    /**
     * Queries that returned zero results since $since. Signal for content gaps or for synonyms we don't yet
     * have. Sorted by frequency so the highest-pain misses surface first.
     */
    public function zeroResultQueries(string $institutionId, CarbonImmutable $since, int $limit = 20): array
    {
        return DB::table('search_queries')->where('institution_id', $institutionId)->where('executed_at', '>=', $since)->where('result_count', 0)
            ->select('query', DB::raw('COUNT(*) as count'), DB::raw('MAX(executed_at) as last_seen'))
            ->groupBy('query')->orderByDesc('count')->limit($limit)->get()
            ->map(fn ($r) => ['query' => $r->query, 'count' => (int) $r->count, 'last_seen' => $r->last_seen])->toArray();
    }

    /** @return array{total: int, zero: int, unique_queries: int, public: int} */
    public function totals(string $institutionId, CarbonImmutable $since): array
    {
        $row = DB::table('search_queries')->where('institution_id', $institutionId)->where('executed_at', '>=', $since)
            ->selectRaw('COUNT(*) AS total, SUM(CASE WHEN result_count = 0 THEN 1 ELSE 0 END) AS zero, COUNT(DISTINCT query) AS unique_queries, SUM(CASE WHEN public THEN 1 ELSE 0 END) AS public')->first();

        return ['total' => (int) ($row->total ?? 0), 'zero' => (int) ($row->zero ?? 0), 'unique_queries' => (int) ($row->unique_queries ?? 0), 'public' => (int) ($row->public ?? 0)];
    }
}
