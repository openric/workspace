<?php

// OpenRiC Workspace - full-text search with facets and highlighting (WS-016, roadmap phase 6, ADR 0009), after
// Heratio's ahg-search on PostgreSQL. A query takes the syntax people type into a search engine (websearch_to_tsquery):
// words, "a phrase", -word to leave out, OR. Facets: kind, level of description, repository; a range of years.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Search;

use App\Models\Institution;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

final class SearchService
{
    /** Marks the highlighting uses before the text is escaped; never in catalogue text. */
    private const START = "\u{E000}";

    private const STOP = "\u{E001}";

    public function __construct(private SearchIndex $index) {}

    /**
     * @param  array{kind?: ?string, level?: ?string, repository?: ?string, from?: ?int, to?: ?int}  $filters
     * @return array{total: int, results: list<array{table: string, id: string, title: string, snippet: HtmlString}>, facets: array{kind: array<string, int>, level: array<string, int>, repository: array<string, int>}}
     */
    public function search(Institution $institution, string $query, bool $public, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['total' => 0, 'results' => [], 'facets' => ['kind' => [], 'level' => [], 'repository' => []]];
        }
        $config = $this->index->config(app()->getLocale());
        $matching = fn () => DB::table('search_entries')->where('institution_id', $institution->id)->where('public', $public)
            ->whereRaw("(plain @@ websearch_to_tsquery('simple', unaccent(?)) OR stemmed @@ websearch_to_tsquery(?::regconfig, unaccent(?)))", [$query, $config, $query]);
        $filtered = function (array $except = []) use ($matching, $filters): Builder {
            $q = $matching();
            if (! in_array('kind', $except, true) && ! empty($filters['kind'])) {
                $q->where('subject_table', $this->table($filters['kind']));
            }
            if (! in_array('level', $except, true) && ! empty($filters['level'])) {
                $q->where('level_id', $filters['level']);
            }
            if (! in_array('repository', $except, true) && ! empty($filters['repository'])) {
                $q->where('repository_id', $filters['repository']);
            }
            if (! empty($filters['from'])) {
                $q->where(fn ($q) => $q->whereNull('latest')->orWhere('latest', '>=', sprintf('%04d-01-01', $filters['from'])));
            }
            if (! empty($filters['to'])) {
                $q->where(fn ($q) => $q->whereNull('earliest')->orWhere('earliest', '<=', sprintf('%04d-12-31', $filters['to'])));
            }

            return $q;
        };

        $started = microtime(true);
        $total = $filtered()->count();
        $rows = $filtered()
            ->selectRaw("subject_table, subject_id, title,
                ts_headline('simple', body, websearch_to_tsquery('simple', ?) || websearch_to_tsquery('simple', unaccent(?)), ?) AS snippet,
                ts_rank_cd(plain, websearch_to_tsquery('simple', unaccent(?))) + ts_rank_cd(stemmed, websearch_to_tsquery(?::regconfig, unaccent(?))) AS rank",
                [$query, $query, 'StartSel='.self::START.', StopSel='.self::STOP.', MaxFragments=2, MaxWords=30, MinWords=10, FragmentDelimiter=" ... "', $query, $config, $query])
            ->orderByDesc('rank')->orderBy('title')->forPage(max(1, $page), $perPage)->get();

        $facet = fn (string $column, string $name) => $filtered([$name])->whereNotNull($column)->groupBy($column)->selectRaw("$column AS value, count(*) AS n")
            ->orderByDesc('n')->limit(20)->pluck('n', 'value')->map(fn ($n) => (int) $n)->all();

        app(SearchAnalyticsService::class)->recordQuery($institution->id, $public, $query, $filters, $total, (microtime(true) - $started) * 1000);

        return [
            'total' => $total,
            'results' => $rows->map(fn ($row) => ['table' => $row->subject_table, 'id' => $row->subject_id, 'title' => $row->title, 'snippet' => $this->highlight($row->snippet)])->all(),
            'facets' => [
                'kind' => collect($facet('subject_table', 'kind'))->mapWithKeys(fn ($n, $table) => [$this->kind($table) => $n])->all(),
                'level' => $facet('level_id', 'level'),
                'repository' => $facet('repository_id', 'repository'),
            ],
        ];
    }

    /** The headline, escaped, with its marks as <mark>. */
    private function highlight(?string $snippet): HtmlString
    {
        return new HtmlString(str_replace([self::START, self::STOP], ['<mark>', '</mark>'], e((string) $snippet)));
    }

    private function table(string $kind): string
    {
        return (new (SearchIndex::KINDS[$kind] ?? SearchIndex::KINDS['records']))->getTable();
    }

    private function kind(string $table): string
    {
        return (string) collect(SearchIndex::KINDS)->search(fn ($class) => (new $class)->getTable() === $table);
    }
}
