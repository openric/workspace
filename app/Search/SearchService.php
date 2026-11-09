<?php

// OpenRiC Workspace - full-text search with facets and highlighting (WS-016, roadmap phase 6, ADR 0009), after
// Heratio's ahg-search on PostgreSQL. A query takes the syntax people type into a search engine (websearch_to_tsquery):
// words, "a phrase", -word to leave out, OR. Facets: kind, level of description, repository; a range of years.
// Phase 25a (after docuteam context; heratio#1583): a search by the words that finds nothing shows the entries whose title
// is closest to it instead (pg_trgm), said to be close matches; and a search may be a regular expression over the indexed
// text, under a time limit. Both read the same public or staff entries, so they show only what the words would.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Search;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Database\QueryException;
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

    /** How long a regular expression may search before it is stopped (phase 25a). */
    public const PATTERN_TIMEOUT = '5s';

    /** How close a title's word must be to the query to be offered as a close match, by pg_trgm's word similarity (phase 25a). */
    public const CLOSE_THRESHOLD = 0.4;

    /**
     * Search the index. Mode (phase 17d1): words (the full-text search, widened by the synonyms the institution's
     * vocabularies hold, or a model suggests, where it allows), meaning (the entries nearest the query in meaning), or
     * both, fused by reciprocal rank. The same filters and facets either way; a page reranked by the account's recent
     * searches where the institution allows it.
     *
     * @param  array{kind?: ?string, level?: ?string, repository?: ?string, from?: ?int, to?: ?int}  $filters
     * With match 'pattern' (by the words only) the query is a regular expression, matched without regard to case.
     *
     * @param  array{mode?: string, user?: ?User, match?: string}  $options
     * @return array{total: int, results: list<array{table: string, id: string, title: string, snippet: HtmlString}>, facets: array{kind: array<string, int>, level: array<string, int>, repository: array<string, int>}, mode: string, expanded: list<string>, match: string, close: bool, error: ?string}
     */
    public function search(Institution $institution, string $query, bool $public, array $filters = [], int $page = 1, int $perPage = 20, array $options = []): array
    {
        $query = trim($query);
        $meaning = app(Meaning::class);
        $mode = in_array($options['mode'] ?? 'words', ['meaning', 'both'], true) && $meaning->available($institution->id) ? $options['mode'] : 'words';
        $match = $mode === 'words' && ($options['match'] ?? 'words') === 'pattern' ? 'pattern' : 'words';
        $nothing = fn (?string $error = null) => ['total' => 0, 'results' => [], 'facets' => ['kind' => [], 'level' => [], 'repository' => []], 'mode' => $mode, 'expanded' => [],
            'match' => $match, 'close' => false, 'error' => $error];
        if ($query === '') {
            return $nothing();
        }
        $user = $options['user'] ?? null;
        $settings = $meaning->settings($institution->id);
        $expanded = $mode === 'meaning' || $match === 'pattern' ? [] : app(Expansion::class)->terms($institution, $query, $settings, $user);
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
        $close = false;
        $closeFacets = null;
        $facet = fn (callable $matched, string $column, string $name) => $matched([$name])->whereNotNull("e.$column")->groupBy("e.$column")->selectRaw("e.$column AS value, count(*) AS n")
            ->orderByDesc('n')->limit(20)->pluck('n', 'value')->map(fn ($n) => (int) $n)->all();
        $facets = fn (callable $matched) => [
            'kind' => collect($facet($matched, 'subject_table', 'kind'))->mapWithKeys(fn ($n, $table) => [$this->kind($table) => $n])->all(),
            'level' => $facet($matched, 'level_id', 'level'),
            'repository' => $facet($matched, 'repository_id', 'repository'),
        ];
        if ($match === 'pattern') {
            $matched = fn (array $except = []) => $apply(DB::table('search_entries as e')->where('e.institution_id', $institution->id)->whereRaw('e.body ~* ?', [$query]), $except);
            try {
                // Its own transaction, so a pattern stopped or refused leaves the connection usable, and the time limit ends with it.
                [$total, $rows, $found] = DB::transaction(function () use ($matched, $facets, $page, $perPage, $query) {
                    DB::statement("SET LOCAL statement_timeout = '".self::PATTERN_TIMEOUT."'");
                    $total = $matched()->count();
                    $rows = $matched()->select('e.subject_table', 'e.subject_id', 'e.title', 'e.body')->orderBy('e.title')->forPage(max(1, $page), $perPage)->get()
                        ->map(fn ($row) => ['table' => $row->subject_table, 'id' => $row->subject_id, 'title' => $row->title, 'snippet' => $this->around((string) $row->body, $query)])->all();
                    $found = $facets($matched);
                    DB::statement('SET LOCAL statement_timeout TO DEFAULT');

                    return [$total, $rows, $found];
                });
            } catch (QueryException $e) {
                // 2201B: PostgreSQL could not read the pattern; 57014: the time limit stopped it.
                return $nothing(match ($e->getCode()) {
                    '2201B' => __('That is not a regular expression the search can read: :reason', ['reason' => Str::of($e->errorInfo[2] ?? '')->after('ERROR:')->before("\n")->trim()->limit(160)]),
                    '57014' => __('The regular expression took too long and was stopped. Make it more specific, for example by anchoring it or naming more letters.'),
                    default => throw $e,
                });
            }
            app(SearchAnalyticsService::class)->recordQuery($institution->id, $public, $query, $filters, $total, (microtime(true) - $started) * 1000);

            return ['total' => $total, 'results' => array_values($rows), 'facets' => $found, 'mode' => $mode, 'expanded' => [], 'match' => $match, 'close' => false, 'error' => null];
        }
        if ($mode === 'words') {
            $total = $words()->count();
            $rows = $words()->selectRaw("e.subject_table, e.subject_id, e.title,
                    ts_headline('simple', e.body, (websearch_to_tsquery('simple', ?) || $plain), ?) AS snippet, ts_rank_cd(e.plain, $plain) + ts_rank_cd(e.stemmed, $stemmed) AS rank",
                [$query, ...$plainBindings, 'StartSel='.self::START.', StopSel='.self::STOP.', MaxFragments=2, MaxWords=30, MinWords=10, FragmentDelimiter=" ... "', ...$plainBindings, ...$stemmedBindings])
                ->orderByDesc('rank')->orderBy('e.title')->forPage(max(1, $page), $perPage)->get()
                ->map(fn ($row) => ['table' => $row->subject_table, 'id' => $row->subject_id, 'title' => $row->title, 'snippet' => $this->highlight($row->snippet)])->all();
            $matched = fn (array $except) => $words($except);
            // Nothing found: the entries whose title holds a word closest to the query, by trigrams (pg_trgm's <%, which the
            // title's index serves). Not for a phrase or a word left out: whoever used them meant exactly that.
            if ($total === 0 && mb_strlen($query) >= 3 && ! preg_match('/"|(^|\s)-\S/u', $query)) {
                $near = fn (array $except = []) => $apply(DB::table('search_entries as e')->where('e.institution_id', $institution->id)->whereRaw('? <% e.title', [$query]), $except);
                [$total, $rows, $found] = DB::transaction(function () use ($near, $facets, $query, $page, $perPage) {
                    // 0.4 rather than pg_trgm's 0.6, so a transposed pair of letters (Minuets) still finds its word.
                    DB::select("SELECT set_config('pg_trgm.word_similarity_threshold', ?, true)", [(string) self::CLOSE_THRESHOLD]);
                    if (($total = $near()->count()) === 0) {
                        return [0, [], null];
                    }
                    $rows = $near()->selectRaw('e.subject_table, e.subject_id, e.title, e.body, word_similarity(?, e.title) AS rank', [$query])
                        ->orderByDesc('rank')->orderBy('e.title')->forPage(max(1, $page), $perPage)->get()
                        ->map(fn ($row) => ['table' => $row->subject_table, 'id' => $row->subject_id, 'title' => $row->title,
                            'snippet' => new HtmlString(e(Str::limit(trim((string) preg_replace('/\s+/u', ' ', $row->body)), 240)))])->all();

                    return [$total, $rows, $facets($near)];
                });
                if ($total > 0) {
                    $close = true;
                    $matched = $near;
                    $closeFacets = $found;
                }
            }
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

        // A search answered only by close matches still counts as finding nothing, for the statistics of searches that fail.
        app(SearchAnalyticsService::class)->recordQuery($institution->id, $public, $query, $filters, $close ? 0 : $total, (microtime(true) - $started) * 1000);
        if ($user !== null && $settings->rerank_history) {
            app(HistoryRerank::class)->remember($user->id, $query);
        }

        return [
            'total' => $total,
            'results' => array_values($rows),
            'facets' => $closeFacets ?? $facets($matched),
            'mode' => $mode,
            'expanded' => $close ? [] : $expanded,
            'match' => $match,
            'close' => $close,
            'error' => null,
        ];
    }

    /**
     * Some 240 characters of the text around the first match of the pattern, marked, where PHP reads the pattern as
     * PostgreSQL did; else the start of the text. ponytail: PCRE and PostgreSQL's regular expressions differ at the edges
     * (classes such as [[:<:]]), where the mark is simply left out.
     */
    private function around(string $text, string $pattern): HtmlString
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $found = @preg_match('~'.str_replace('~', '\~', $pattern).'~iu', $text, $m, PREG_OFFSET_CAPTURE);
        if (! $found || $m[0][0] === '') {
            return new HtmlString(e(Str::limit($text, 240)));
        }
        [$hit, $at] = $m[0];
        $from = max(0, $at - 100);
        $before = substr($text, $from, $at - $from);
        $after = substr($text, $at + strlen($hit), 140);

        return new HtmlString(($from > 0 ? '... ' : '').e(mb_scrub($before)).'<mark>'.e($hit).'</mark>'.e(mb_scrub($after)).' ...');
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
