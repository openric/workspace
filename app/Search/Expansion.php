<?php

// OpenRiC Workspace - widening a search (roadmap phase 17d1), after Heratio's SemanticSearchService::expandQuery and
// ahg-ai-services QueryExpansionService: each word of a query that names a concept of the institution's vocabularies
// that describe (by its preferred or an alternative label, in any language) brings that concept's other labels; and, where the
// institution allows and AI is on, a language model through the gateway suggests a few more terms. At most so many terms
// (five unless the institution sets otherwise), each searched as well as the query, never instead of it.
// Changed from Heratio: the synonyms are the SKOS labels the institution already keeps, not a separate thesaurus table to
// sync; the model's terms come with a receipt in the log of inferences.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Search;

use App\Ai\AiUnavailable;
use App\Ai\Gateway;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class Expansion
{
    /** @return list<string> terms to search as well as the query */
    public function terms(Institution $institution, string $query, object $settings, ?User $user = null): array
    {
        $limit = (int) $settings->expansion_limit;
        if ($limit === 0) {
            return [];
        }
        $terms = $settings->expand_synonyms ? $this->synonyms($institution->id, $query) : [];
        if ($settings->expand_with_model && count($terms) < $limit && app(Gateway::class)->available($institution->id)) {
            $terms = [...$terms, ...$this->fromModel($institution, $query, $user)];
        }
        $words = array_map('mb_strtolower', self::words($query));
        $out = [];
        foreach ($terms as $term) {
            $key = mb_strtolower($term);
            if (! in_array($key, $words, true) && ! isset($out[$key]) && mb_strlen($term) >= 3) {
                $out[$key] = $term;
            }
        }

        return array_slice(array_values($out), 0, $limit);
    }

    /** The other labels of the concepts a word of the query names, the institution's own or the bundled ones. @return list<string> */
    public function synonyms(string $institutionId, string $query): array
    {
        $words = array_values(array_filter(self::words($query), fn ($w) => mb_strlen($w) >= 3));
        if ($words === []) {
            return [];
        }
        $lower = array_map('mb_strtolower', $words);
        $labels = fn () => DB::table('concept_labels')->select('concept_id', DB::raw('pref_label AS label'))
            ->unionAll(DB::table('concept_alt_labels')->where('is_hidden', false)->select('concept_id', 'label'));
        $concepts = DB::query()->fromSub($labels(), 'l')->join('concepts as c', 'c.id', '=', 'l.concept_id')
            // Only the vocabularies that describe (subjects, places, genres and the like: those with a kind), never the operational lists.
            ->join('vocabularies as v', 'v.id', '=', 'c.vocabulary_id')->whereNotNull('v.kind_id')->where(fn ($q) => $q->whereNull('v.institution_id')->orWhere('v.institution_id', $institutionId))
            ->whereNull('c.deprecated_at')->where(fn ($q) => $q->whereNull('c.institution_id')->orWhere('c.institution_id', $institutionId))
            ->whereIn(DB::raw('lower(l.label)'), $lower)->distinct()->limit(20)->pluck('l.concept_id');

        return $concepts->isEmpty() ? [] : DB::query()->fromSub($labels(), 'l')->whereIn('l.concept_id', $concepts)->whereNotIn(DB::raw('lower(l.label)'), $lower)
            ->distinct()->limit(20)->pluck('l.label')->all();
    }

    /** Heratio's query expansion by a language model: 3 to 8 terms, one line, comma-separated. @return list<string> */
    private function fromModel(Institution $institution, string $query, ?User $user): array
    {
        $model = app(Gateway::class)->settings($institution->id)->chat_model ?: (string) config('workspace.ai.chat_model');
        $prompt = 'You are a search-query expansion assistant for an archival / library catalogue. Given a natural-language query, output ONLY a '
            .'single comma-separated line of 3 to 8 concise search terms: synonyms, broader/narrower concepts, and proper nouns that would '
            .'improve recall. No explanations, no numbering, no quotes. Language: '.app()->getLocale().".\n\nQuery: {$query}\n\nTerms:";
        try {
            $call = app(Gateway::class)->call($institution, 'chat', 'ollama/api/chat', ['model' => $model, 'stream' => false, 'think' => false,
                'options' => ['temperature' => 0.2], 'messages' => [['role' => 'user', 'content' => $prompt]]],
                ['field' => 'search-expansion', 'user' => $user, 'input' => $prompt, 'input_excerpt' => $query],
                fn (array $answer) => ['output' => trim((string) preg_replace('/<think>.*?<\/think>/s', '', (string) ($answer['message']['content'] ?? ''))),
                    'model' => (string) ($answer['model'] ?? $model)]);
        } catch (AiUnavailable) {
            return [];
        }

        return self::parseTerms($call['read']['output'], $query);
    }

    /**
     * Heratio's QueryExpansionService::parseTerms: the first line that is a list, a leading "label:" dropped, list
     * markers and quotes stripped, the query's own words and terms under three letters left out, at most eight.
     *
     * @return list<string>
     */
    public static function parseTerms(string $raw, string $query): array
    {
        $line = trim($raw);
        foreach (preg_split('/\r?\n/', $raw) as $candidate) {
            if (str_contains($candidate, ',')) {
                $line = $candidate;
                break;
            }
            if (trim($candidate) !== '') {
                $line = $candidate;
            }
        }
        $colon = strpos($line, ':');
        $comma = strpos($line, ',');
        if ($colon !== false && ($comma === false || $colon < $comma)) {
            $line = trim(substr($line, $colon + 1));
        }
        $queryWords = array_map('mb_strtolower', preg_split('/\s+/', $query) ?: []);
        $terms = [];
        foreach (preg_split('/[,;\n]/', $line) ?: [] as $part) {
            $term = trim((string) preg_replace('/^[\s\-\*\d\.\)\("\']+/u', '', trim($part)), " \t\"'.");
            $lower = mb_strtolower($term);
            if ($term === '' || mb_strlen($term) < 3 || in_array($lower, $queryWords, true)) {
                continue;
            }
            $terms[$lower] = $term;
            if (count($terms) >= 8) {
                break;
            }
        }

        return array_values($terms);
    }

    /** @return list<string> */
    private static function words(string $query): array
    {
        return preg_split('/[^\p{L}\p{N}\'-]+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
