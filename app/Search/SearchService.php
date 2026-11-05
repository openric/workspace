<?php

// OpenRiC Workspace - full-text search with facets and highlighting (WS-016, roadmap phase 6, ADR 0009), after
// Heratio's ahg-search on PostgreSQL. A query takes the syntax people type into a search engine (websearch_to_tsquery):
// words, "a phrase", -word to leave out, OR. Facets: kind, level of description, repository; a range of years.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Search;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

final class SearchService
{
    /** Marks the highlighting uses before the text is escaped; never in catalogue text. */
    private const START = "\u{E000}";

    private const STOP = "\u{E001}";

    public function __construct(private SearchIndex $index) {}

    /** Reciprocal rank fusion's constant: how much a first place outweighs a tenth (phase 17d1). */
    private const RRF_K = 60;

    /**
     * Search the index. Mode (phase 17d1): words (the full-text search, widened by the synonyms the institution's
     * vocabularies hold, or a model suggests, where it allows), meaning (the entries nearest the query in meaning), or
     * both, fused by reciprocal rank. The same filters and facets either way; a page reranked by the account's recent
     * searches where the institution allows it.
     *
     * @param  array{kind?: ?string, level?: ?string, repository?: ?string, from?: ?int, to?: ?int}  $filters
     * @param  array{mode?: string, user?: ?User}  $options
     * @return array{total: int, results: list<array{table: string, id: string, title: string, snippet: HtmlString}>, facets: array{kind: array<string, int>, level: array<string, int>, repository: array<string, int>}, mode: string, expanded: list<string>}
     */
    public function search(Institution $institution, string $query, bool $public, array $filters = [], int $page = 1, int $perPage = 20, array $options = []): array
    {
        $query = trim($query);
        $meaning = app(Meaning::class);
        $mode = in_array($options['mode'] ?? 'words', ['meaning', 'both'], true) && $meaning->available($institution->id) ? $options['mode'] : 'words';
        if ($query === '') {
            return ['total' => 0, 'results' => [], 'facets' => ['kind' => [], 'level' => [], 'repository' => []], 'mode' => $mode, 'expanded' => []];
        }
        $user = $options['user'] ?? null;
        $settings = $meaning->settings($institution->id);
        $expanded = $mode === 'meaning' ? [] : app(Expansion::class)->terms($institution, $query, $settings, $user);
        $config = $this->index->config(app()->getLocale());
        // The query and its widening terms, each its own tsquery, joined by OR.
        $plain = "(websearch_to_tsquery('simple', unaccent(?))".str_repeat(" || plainto_tsquery('simple', unaccent(?))", count($expanded)).')';
        $stemmed = '(websearch_to_tsquery(?::regconfig, unaccent(?))'.str_repeat(' || plainto_tsquery(?::regconfig, unaccent(?))', count($expanded)).')';
        $plainBindings = [$query, ...$expanded];
        $stemmedBindings = [$config, $query];
        foreach ($expanded as $term) {
            array_push($stemmedBindings, $config, $term);
        }
        $apply = function (Builder $q, array $except = []) use ($filters, $public): Builder {
            if (! in_array('kind', $except, true) && ! empty($filters['kind'])) {
                $q->where('e.subject_table', $this->table($filters['kind']));
            }
            if (! in_array('level', $except, true) && ! empty($filters['level'])) {
                $q->where('e.level_id', $filters['level']);
            }
            if (! in_array('repository', $except, true) && ! empty($filters['repository'])) {
                $q->where('e.repository_id', $filters['repository']);
            }
            if (! empty($filters['from'])) {
                $q->where(fn ($q) => $q->whereNull('e.latest')->orWhere('e.latest', '>=', sprintf('%04d-01-01', $filters['from'])));
            }
            if (! empty($filters['to'])) {
                $q->where(fn ($q) => $q->whereNull('e.earliest')->orWhere('e.earliest', '<=', sprintf('%04d-12-31', $filters['to'])));
            }

            return $q->where('e.public', $public);
        };
        $words = fn (array $except = []) => $apply(DB::table('search_entries as e')->where('e.institution_id', $institution->id)
            ->whereRaw("(e.plain @@ $plain OR e.stemmed @@ $stemmed)", [...$plainBindings, ...$stemmedBindings]), $except);

        $started = microtime(true);
        if ($mode === 'words') {
            $total = $words()->count();
            $rows = $words()->selectRaw("e.subject_table, e.subject_id, e.title,
                    ts_headline('simple', e.body, (websearch_to_tsquery('simple', ?) || $plain), ?) AS snippet, ts_rank_cd(e.plain, $plain) + ts_rank_cd(e.stemmed, $stemmed) AS rank",
                [$query, ...$plainBindings, 'StartSel='.self::START.', StopSel='.self::STOP.', MaxFragments=2, MaxWords=30, MinWords=10, FragmentDelimiter=" ... "', ...$plainBindings, ...$stemmedBindings])
                ->orderByDesc('rank')->orderBy('e.title')->forPage(max(1, $page), $perPage)->get()
                ->map(fn ($row) => ['table' => $row->subject_table, 'id' => $row->subject_id, 'title' => $row->title, 'snippet' => $this->highlight($row->snippet)])->all();
            $matched = fn (array $except) => $words($except);
        } else {
            // By meaning, or both fused by reciprocal rank: each list's place counts, not its scale.
            $near = $meaning->nearest($institution, $query, fn (Builder $q) => $apply($q), 200, $user);
            $scores = [];
            foreach (array_values($near) as $i => $row) {
                $scores[$row->id] = 1 / (self::RRF_K + $i + 1);
            }
            if ($mode === 'both') {
                foreach ($words()->selectRaw("e.id, ts_rank_cd(e.plain, $plain) + ts_rank_cd(e.stemmed, $stemmed) AS rank", [...$plainBindings, ...$stemmedBindings])
                    ->orderByDesc('rank')->limit(200)->pluck('id')->values() as $i => $id) {
                    $scores[$id] = ($scores[$id] ?? 0) + 1 / (self::RRF_K + $i + 1);
                }
            }
            arsort($scores);
            $ids = array_keys($scores);
            $total = count($ids);
            $pageIds = array_slice($ids, (max(1, $page) - 1) * $perPage, $perPage);
            $entries = DB::table('search_entries')->whereIn('id', $pageIds)->get(['id', 'subject_table', 'subject_id', 'title', 'body'])->keyBy('id');
            $rows = collect($pageIds)->map(fn ($id) => $entries[$id])->map(fn ($row) => ['table' => $row->subject_table, 'id' => $row->subject_id, 'title' => $row->title,
                'snippet' => new HtmlString(e(Str::limit(trim((string) preg_replace('/\s+/u', ' ', $row->body)), 240)))])->all();
            $matched = fn (array $except) => $apply(DB::table('search_entries as e')->whereIn('e.id', $ids ?: ['00000000-0000-0000-0000-000000000000']), $except);
        }
        if ($user !== null && $settings->rerank_history) {
            $rows = app(HistoryRerank::class)->rerank($rows, $user->id);
        }

        $facet = fn (string $column, string $name) => $matched([$name])->whereNotNull("e.$column")->groupBy("e.$column")->selectRaw("e.$column AS value, count(*) AS n")
            ->orderByDesc('n')->limit(20)->pluck('n', 'value')->map(fn ($n) => (int) $n)->all();

        app(SearchAnalyticsService::class)->recordQuery($institution->id, $public, $query, $filters, $total, (microtime(true) - $started) * 1000);
        if ($user !== null && $settings->rerank_history) {
            app(HistoryRerank::class)->remember($user->id, $query);
        }

        return [
            'total' => $total,
            'results' => array_values($rows),
            'facets' => [
                'kind' => collect($facet('subject_table', 'kind'))->mapWithKeys(fn ($n, $table) => [$this->kind($table) => $n])->all(),
                'level' => $facet('level_id', 'level'),
                'repository' => $facet('repository_id', 'repository'),
            ],
            'mode' => $mode,
            'expanded' => $expanded,
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
