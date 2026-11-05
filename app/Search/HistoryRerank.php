<?php

/**
 * HistoryRerankService - opt-in personalised re-ranking of a result page.
 * Boosts results whose title / snippet overlaps with the terms the
 * authenticated user has searched for recently (last N rows of
 * ahg_search_query_log). Pure in-memory, stable reorder of a single page of
 * results - it never re-queries and never changes the total count, so it is
 * cheap and safe to apply per request.
 * Scoring: each result keeps its original ordinal position (lower = better).
 * A boost is subtracted from that position for every recent-query term the
 * result matches, weighted by term recency. Ties fall back to the original
 * order (stable sort).
 *
 * @author     Johan Pieterse
 * @license    AGPL-3.0-or-later
 *
 * OpenRiC Workspace - copied from Heratio, packages/ahg-semantic-search/src/Services/HistoryRerankService.php at commit
 * bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md, roadmap phase 17d1). Changed: the history
 * is the account's own search_history, kept only while its institution reranks and only the last 25, since the
 * Workspace's search statistics keep no one's name; the namespace and code style.
 */

declare(strict_types=1);

namespace App\Search;

use Illuminate\Support\Facades\DB;

class HistoryRerank
{
    /** How many recent query rows to mine for a user. */
    protected int $historyDepth = 25;

    /** Boost (in ordinal positions) applied per matched term. */
    protected float $perTermBoost = 1.5;

    /** Stop words excluded from the user's interest profile. */
    protected array $stopWords = ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'from', 'is', 'are', 'was', 'were', 'be'];

    /**
     * Re-rank a single page of results for a user.
     *
     * @param  list<array{title?: string, snippet?: mixed}>  $results
     * @return list<array>
     */
    public function rerank(array $results, string $userId): array
    {
        if (count($results) < 2) {
            return $results;
        }
        $profile = $this->buildInterestProfile($userId);
        if ($profile === []) {
            return $results;
        }
        $decorated = [];
        foreach (array_values($results) as $i => $row) {
            $haystack = mb_strtolower(($row['title'] ?? '').' '.strip_tags((string) ($row['snippet'] ?? '')));
            $boost = 0.0;
            foreach ($profile as $term => $weight) {
                if ($term !== '' && str_contains($haystack, (string) $term)) {
                    $boost += $this->perTermBoost * $weight;
                }
            }
            // Lower effective position sorts first; the boost pulls a matched result forward.
            $decorated[] = ['orig' => $i, 'score' => $i - $boost, 'row' => $row];
        }
        usort($decorated, fn ($a, $b) => $a['score'] <=> $b['score'] ?: $a['orig'] <=> $b['orig']);

        return array_map(fn ($d) => $d['row'], $decorated);
    }

    /**
     * Build a term -> recency-weight map from the user's recent searches. More-recent queries weigh slightly more.
     *
     * @return array<string, float>
     */
    protected function buildInterestProfile(string $userId): array
    {
        $rows = DB::table('search_history')->where('user_id', $userId)->orderByDesc('executed_at')->orderByDesc('id')->limit($this->historyDepth)->pluck('query')->all();
        $profile = [];
        $total = count($rows);
        foreach ($rows as $idx => $query) {
            // Recency weight 1.0 (newest) .. ~0.3 (oldest in window).
            $recency = 1.0 - (0.7 * ($idx / max(1, $total)));
            foreach ($this->tokenize((string) $query) as $term) {
                $profile[$term] = ($profile[$term] ?? 0.0) + $recency;
            }
        }

        // Cap influence of any single term.
        return array_map(fn ($v) => min(3.0, $v), $profile);
    }

    /** Keep a search in the account's own history, the last $historyDepth only. */
    public function remember(string $userId, string $query): void
    {
        DB::table('search_history')->insert(['user_id' => $userId, 'query' => mb_substr(trim($query), 0, 512), 'executed_at' => now()]);
        $keep = DB::table('search_history')->where('user_id', $userId)->orderByDesc('executed_at')->orderByDesc('id')->limit($this->historyDepth)->pluck('id');
        DB::table('search_history')->where('user_id', $userId)->whereNotIn('id', $keep)->delete();
    }

    /** @return list<string> */
    protected function tokenize(string $query): array
    {
        $words = preg_split('/\s+/', (string) preg_replace('/[^\w\s]/u', ' ', mb_strtolower($query)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($words, fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, $this->stopWords, true)));
    }
}
