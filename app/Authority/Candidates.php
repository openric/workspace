<?php

// OpenRiC Workspace - the authority records or places a mention may be (roadmap phase 17b2), after Heratio's
// CandidateGeneratorService with its MysqlActorAdapter and MysqlTermAdapter: the institution's own authority records of
// the right kind (people for a person, corporate bodies and families for an organisation) or places, found by every
// word of the name in any of their names, ranked by how alike the names are, the best kept (five unless the
// institution sets otherwise). Heratio's name similarity is kept (similar_text, a small lift when one contains the
// other, 1 for the same name), taken also on the words in order, so "Botha, Maria" is "Maria Botha".
// Changed from Heratio: every word of the name, in any of a record's names, rather than the whole name in its
// authorised form, so an inverted or variant name is found; no Fuseki adapters, as the Workspace's triplestore is fed
// from these same records.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Authority;

use App\Models\Agent;
use App\Models\AuthorityCandidate;
use App\Models\AuthorityMention;
use App\Models\Name;
use App\Models\Place;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Candidates
{
    private const PER_SOURCE_LIMIT = 50;

    public function __construct(private Settings $settings) {}

    /** Generate and keep the mention's ranked candidates, replacing any it had. @return Collection<int, AuthorityCandidate> */
    public function generate(AuthorityMention $mention): Collection
    {
        $value = $mention->value();
        $scored = $this->search($mention->institution_id, $value, $mention->label())
            ->map(fn ($c) => $c + ['score' => self::similarity($value, $c['display_name'])])
            ->sort(fn ($a, $b) => [$b['score'], $a['display_name']] <=> [$a['score'], $b['display_name']])
            ->take($this->settings->topN($mention->institution_id))->values();

        return DB::transaction(function () use ($mention, $scored) {
            AuthorityCandidate::where('mention_id', $mention->id)->delete();

            return $scored->map(fn ($c, $i) => AuthorityCandidate::create(['mention_id' => $mention->id, 'rank' => $i + 1, 'candidate_table' => $c['table'],
                'candidate_id' => $c['id'], 'display_name' => $c['display_name'], 'name_similarity' => $c['score'], 'composite' => $c['score'], 'computed_at' => now()]));
        });
    }

    /**
     * The institution's agents or places whose names hold every word of $value, each once, by its authorised name.
     *
     * @return Collection<int, array{table: string, id: string, display_name: string}>
     */
    public function search(string $institutionId, string $value, string $label): Collection
    {
        $words = array_values(array_filter(preg_split('/[\s,.;]+/u', mb_strtolower(trim($value))), fn ($w) => mb_strlen($w) > 1));
        if ($words === []) {
            return collect();
        }
        $place = in_array($label, AuthorityMention::PLACES, true);
        $table = $place ? 'places' : 'agents';
        $ids = Name::where('subject_table', $table)->where('institution_id', $institutionId)
            ->where(function ($q) use ($words) {
                foreach ($words as $w) {
                    $q->where('value', 'ilike', '%'.addcslashes($w, '%_\\').'%');
                }
            })->limit(self::PER_SOURCE_LIMIT * 4)->pluck('subject_id')->unique()->values();
        $entities = $place
            ? Place::whereIn('id', $ids)->whereNull('deleted_at')->whereNull('merged_into_id')->with('names.nameType')->limit(self::PER_SOURCE_LIMIT)->get()
            : Agent::whereIn('id', $ids)->whereNull('deleted_at')->with('names.nameType', 'agentType')
                ->whereHas('agentType', fn ($q) => $q->whereIn('notation', $label === 'PERSON' ? ['person'] : ['corporate-body', 'family']))->limit(self::PER_SOURCE_LIMIT)->get();

        return $entities->map(fn ($e) => ['table' => $table, 'id' => $e->id, 'display_name' => (string) ($e->described('authorised-name', ['en', 'af']) ?? $e->names->first()?->value)])
            ->filter(fn ($c) => $c['display_name'] !== '')->values();
    }

    /** Heratio's name-only similarity, taken on the names as written and on their words in order; the better of the two. */
    public static function similarity(string $mention, string $candidate): float
    {
        $q = trim(mb_strtolower($mention, 'UTF-8'));
        $c = trim(mb_strtolower($candidate, 'UTF-8'));
        if ($q === '' || $c === '') {
            return 0.0;
        }
        $score = function (string $q, string $c): float {
            similar_text($q, $c, $percent);
            $score = $percent / 100.0;
            if (str_contains($c, $q)) {
                $score = min(1.0, $score + 0.05);
            }

            return $q === $c ? 1.0 : $score;
        };
        $sorted = fn (string $s) => implode(' ', collect(preg_split('/[\s,.;]+/u', $s))->filter()->sort()->all());

        return round(max($score($q, $c), $score($sorted($q), $sorted($c))), 4);
    }
}
