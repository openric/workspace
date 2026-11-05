<?php

// OpenRiC Workspace - an institution's authority resolution settings (roadmap phase 17b2), after Heratio's
// authority_resolution.* and lookup.* settings: how many candidates a mention is given, the words of role looked for
// near a name (Heratio's defaults, with Afrikaans beside them, until the institution sets its own), and each outside
// authority: on or off (off by default), its rate limit, how long its answers are kept, its username where it needs one
// (GeoNames), and the order in which sources fill a field.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Authority;

use App\Models\Concept;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;

class Settings
{
    public const DEFAULT_TOP_N = 5;

    /** Heratio's rate limits (calls a minute) and cache times (seconds), by source. */
    public const SOURCE_DEFAULTS = ['viaf' => [60, 604800], 'wikidata' => [120, 604800], 'geonames' => [60, 604800], 'tgn' => [30, 1209600],
        'gnd' => [60, 604800], 'isni' => [60, 604800], 'sagnc' => [30, 2592000]];

    /** The sources searched today; the others are Heratio's stubs, listed but not yet searched. */
    public const SEARCHED = ['viaf', 'wikidata', 'geonames'];

    /** Licence and its address, as Heratio's seed gives them. */
    public const LICENCES = [
        'viaf' => ['CC0-1.0', 'https://creativecommons.org/publicdomain/zero/1.0/'],
        'wikidata' => ['CC0-1.0', 'https://creativecommons.org/publicdomain/zero/1.0/'],
        'geonames' => ['CC-BY-4.0', 'https://creativecommons.org/licenses/by/4.0/'],
        'tgn' => ['ODC-BY-1.0', 'https://www.getty.edu/research/tools/vocabularies/obtain/license.html'],
        'gnd' => ['CC0-1.0', 'https://creativecommons.org/publicdomain/zero/1.0/'],
        'isni' => ['ODC-BY-1.0', 'https://opendatacommons.org/licenses/by/1-0/'],
        'sagnc' => ['Crown Copyright (Republic of South Africa)', 'https://www.gov.za/services/services-organisations/south-african-geographical-names-council'],
    ];

    public function topN(string $institutionId): int
    {
        return (int) (DB::table('authority_settings')->where('institution_id', $institutionId)->value('candidate_top_n') ?? self::DEFAULT_TOP_N);
    }

    /** @return array<string, list<string>> kind => words of role */
    public function roleTokens(string $institutionId): array
    {
        $own = json_decode((string) DB::table('authority_settings')->where('institution_id', $institutionId)->value('role_tokens'), true);

        return is_array($own) && $own !== [] ? $own : json_decode((string) file_get_contents(resource_path('authority/role-language-tokens.json')), true);
    }

    /** @param  array<string, list<string>>  $tokens */
    public function save(string $institutionId, int $topN, array $tokens): void
    {
        $kinds = Concept::choices(Vocab::BASE.'role-language-kind/')->pluck('notation')->all();
        $tokens = collect($tokens)->only($kinds)->map(fn ($list) => array_values(array_unique(array_filter(array_map(fn ($t) => mb_strtolower(trim((string) $t)), (array) $list)))))->all();
        DB::table('authority_settings')->upsert([['institution_id' => $institutionId, 'candidate_top_n' => max(1, min(50, $topN)),
            'role_tokens' => json_encode($tokens, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]], ['institution_id'], ['candidate_top_n', 'role_tokens', 'updated_at']);
    }

    /**
     * Each outside authority as the institution has it set.
     *
     * @return array<string, array{source_id: string, enabled: bool, rate_limit: int, cache_ttl: int, username: ?string, precedence: int, searched: bool}>
     */
    public function sources(string $institutionId): array
    {
        $rows = DB::table('authority_lookup_settings')->where('institution_id', $institutionId)->get()->keyBy('source_id');
        $out = [];
        foreach (Concept::choices(Vocab::BASE.'lookup-source/')->get() as $i => $source) {
            $row = $rows[$source->id] ?? null;
            [$rate, $ttl] = self::SOURCE_DEFAULTS[$source->notation] ?? [60, 604800];
            $out[$source->notation] = ['source_id' => $source->id, 'enabled' => (bool) ($row->enabled ?? false), 'rate_limit' => (int) ($row->rate_limit ?? $rate),
                'cache_ttl' => (int) ($row->cache_ttl ?? $ttl), 'username' => $row->username ?? null, 'precedence' => (int) ($row->precedence ?? $i),
                'searched' => in_array($source->notation, self::SEARCHED, true)];
        }
        uasort($out, fn ($a, $b) => $a['precedence'] <=> $b['precedence']);

        return $out;
    }

    /** @param  array<string, array{enabled?: mixed, rate_limit?: mixed, cache_ttl?: mixed, username?: ?string, precedence?: mixed}>  $sources */
    public function saveSources(string $institutionId, array $sources): void
    {
        foreach ($this->sources($institutionId) as $notation => $current) {
            $given = $sources[$notation] ?? [];
            DB::table('authority_lookup_settings')->upsert([['institution_id' => $institutionId, 'source_id' => $current['source_id'],
                'enabled' => $current['searched'] && ! empty($given['enabled']), 'rate_limit' => max(1, min(1000, (int) ($given['rate_limit'] ?? $current['rate_limit']))),
                'cache_ttl' => max(60, (int) ($given['cache_ttl'] ?? $current['cache_ttl'])), 'username' => trim((string) ($given['username'] ?? '')) ?: null,
                'precedence' => (int) ($given['precedence'] ?? $current['precedence']), 'created_at' => now(), 'updated_at' => now()]],
                ['institution_id', 'source_id'], ['enabled', 'rate_limit', 'cache_ttl', 'username', 'precedence', 'updated_at']);
        }
    }
}
