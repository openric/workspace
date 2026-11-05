<?php

// OpenRiC Workspace - search by meaning (roadmap phase 17d1), after Heratio's ahg-semantic-search and the vector search
// its related records read: every entry of the search index turned into a vector by the AI gateway's embedding model,
// kept beside it and made again when its text changes; a query turned into a vector the same way and matched to the
// nearest entries, as the institution's audience may see them; and the entries nearest to one entry, its related
// records. Every embedding call is a receipt in the log of inferences.
// Changed from Heratio: pgvector in the Workspace's own database rather than a Qdrant collection, so the nearest
// entries are found with the same filters as the full-text search (institution, audience, kind, level, repository,
// years), and a unit no visitor may see is never near anything a visitor sees.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Search;

use App\Ai\Gateway;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Meaning
{
    public const DEFAULT_MODEL = 'nomic-embed-text';

    /** As much of an entry as is embedded; nomic-embed-text reads about 8,000 tokens. */
    private const MAX_TEXT = 6000;

    private const BATCH = 32;

    public function __construct(private Gateway $gateway) {}

    /** @return object{by_meaning: bool, expand_synonyms: bool, expand_with_model: bool, rerank_history: bool, expansion_limit: int, embedding_model: ?string} */
    public function settings(string $institutionId): object
    {
        return DB::table('search_settings')->where('institution_id', $institutionId)->first()
            ?? (object) ['by_meaning' => false, 'expand_synonyms' => true, 'expand_with_model' => false, 'rerank_history' => false, 'expansion_limit' => 5, 'embedding_model' => null, 'min_similarity' => 0.40];
    }

    public function saveSettings(string $institutionId, array $data): void
    {
        DB::table('search_settings')->upsert([['institution_id' => $institutionId, 'by_meaning' => ! empty($data['by_meaning']), 'expand_synonyms' => ! empty($data['expand_synonyms']),
            'expand_with_model' => ! empty($data['expand_with_model']), 'rerank_history' => ! empty($data['rerank_history']),
            'expansion_limit' => max(0, min(20, (int) ($data['expansion_limit'] ?? 5))), 'embedding_model' => trim((string) ($data['embedding_model'] ?? '')) ?: null,
            'min_similarity' => max(0.0, min(0.99, (float) ($data['min_similarity'] ?? 0.40))), 'created_at' => now(), 'updated_at' => now()]],
            ['institution_id'], ['by_meaning', 'expand_synonyms', 'expand_with_model', 'rerank_history', 'expansion_limit', 'embedding_model', 'min_similarity', 'updated_at']);
    }

    /** Does the institution search by meaning now: switched on, and AI available? */
    public function available(string $institutionId): bool
    {
        return (bool) $this->settings($institutionId)->by_meaning && $this->gateway->available($institutionId);
    }

    /**
     * Embed the institution's entries that have no vector, or whose text has changed. @return int entries embedded
     */
    public function index(Institution $institution, int $limit = 500, ?User $user = null): int
    {
        $model = $this->model($institution->id);
        $done = 0;
        $pending = DB::table('search_entries as e')->leftJoin('search_vectors as v', 'v.search_entry_id', '=', 'e.id')->where('e.institution_id', $institution->id)
            ->where(fn ($q) => $q->whereNull('v.search_entry_id')->orWhere('v.model', '!=', $model)
                ->orWhereRaw("v.text_hash <> encode(sha256(convert_to(left(e.title || E'\\n\\n' || e.body, ?), 'UTF8')), 'hex')", [self::MAX_TEXT]))
            ->orderBy('e.id')->limit($limit)->get(['e.id', 'e.title', 'e.body']);
        foreach ($pending->chunk(self::BATCH) as $batch) {
            $texts = $batch->map(fn ($e) => mb_substr($e->title."\n\n".$e->body, 0, self::MAX_TEXT))->values()->all();
            $vectors = $this->embed($institution, $texts, $user, 'search-index');
            foreach ($batch->values() as $i => $entry) {
                DB::statement('INSERT INTO search_vectors (search_entry_id, institution_id, model, text_hash, embedded_at, embedding) VALUES (?, ?, ?, ?, now(), ?::vector)
                    ON CONFLICT (search_entry_id) DO UPDATE SET model = EXCLUDED.model, text_hash = EXCLUDED.text_hash, embedded_at = EXCLUDED.embedded_at, embedding = EXCLUDED.embedding',
                    [$entry->id, $institution->id, $model, hash('sha256', $texts[$i]), self::literal($vectors[$i])]);
                $done++;
            }
        }

        return $done;
    }

    /**
     * The entries nearest a query in meaning, under the filters the caller sets on the entries (alias e).
     *
     * @param  callable(Builder): Builder  $filter
     * @return list<object{id: string, subject_table: string, subject_id: string, title: string, body: string, similarity: float}>
     */
    public function nearest(Institution $institution, string $query, callable $filter, int $limit = 100, ?User $user = null): array
    {
        $vector = self::literal($this->embed($institution, [$query], $user, 'search-query')[0]);

        return $filter(DB::table('search_entries as e')->join('search_vectors as v', 'v.search_entry_id', '=', 'e.id')->where('e.institution_id', $institution->id))
            ->whereRaw('1 - (v.embedding <=> ?::vector) >= ?', [$vector, (float) $this->settings($institution->id)->min_similarity])
            ->selectRaw('e.id, e.subject_table, e.subject_id, e.title, e.body, 1 - (v.embedding <=> ?::vector) AS similarity', [$vector])
            ->orderByRaw('v.embedding <=> ?::vector', [$vector])->limit($limit)->get()->all();
    }

    /**
     * The entries nearest one entry: a unit's related records, as the same audience sees them.
     *
     * @return list<object{subject_table: string, subject_id: string, title: string, similarity: float}>
     */
    public function related(string $subjectTable, string $subjectId, bool $public, int $limit = 6): array
    {
        $own = DB::table('search_entries as e')->join('search_vectors as v', 'v.search_entry_id', '=', 'e.id')
            ->where(['e.subject_table' => $subjectTable, 'e.subject_id' => $subjectId, 'e.public' => $public])->first(['e.id', 'e.institution_id']);
        if ($own === null) {
            return [];
        }

        return DB::table('search_entries as e')->join('search_vectors as v', 'v.search_entry_id', '=', 'e.id')
            ->crossJoin(DB::raw('(SELECT embedding AS q FROM search_vectors WHERE search_entry_id = '.DB::getPdo()->quote($own->id).') AS own'))
            ->where(['e.institution_id' => $own->institution_id, 'e.public' => $public, 'e.subject_table' => $subjectTable])->where('e.id', '!=', $own->id)
            ->selectRaw('e.subject_table, e.subject_id, e.title, 1 - (v.embedding <=> own.q) AS similarity')->orderByRaw('v.embedding <=> own.q')->limit($limit)->get()->all();
    }

    /** @return list<list<float>> one vector a text, through the gateway's Ollama embed route */
    private function embed(Institution $institution, array $texts, ?User $user, string $field): array
    {
        $model = $this->model($institution->id);
        $call = $this->gateway->call($institution, 'embed', 'ollama/api/embed', ['model' => $model, 'input' => $texts],
            ['field' => $field, 'user' => $user, 'input' => implode("\n\u{1E}\n", $texts), 'input_excerpt' => count($texts).' texts: '.mb_substr($texts[0] ?? '', 0, 300)],
            function (array $answer) use ($texts, $model) {
                $vectors = $answer['embeddings'] ?? null;
                is_array($vectors) && count($vectors) === count($texts) || throw new InvalidArgumentException('the gateway gave '.count((array) $vectors).' vectors for '.count($texts).' texts');
                foreach ($vectors as $v) {
                    count($v) === 768 || throw new InvalidArgumentException('a vector of '.count($v).' dimensions, not 768');
                }

                return ['output' => count($vectors).' vectors of 768 dimensions', 'model' => (string) ($answer['model'] ?? $model), 'details' => ['vectors' => count($vectors)]];
            });

        return $call['answer']['embeddings'];
    }

    private function model(string $institutionId): string
    {
        return $this->settings($institutionId)->embedding_model ?: self::DEFAULT_MODEL;
    }

    /** A vector as pgvector reads it: [0.1,0.2,...]. */
    public static function literal(array $vector): string
    {
        return '['.implode(',', array_map(fn ($x) => sprintf('%.7F', (float) $x), $vector)).']';
    }
}
