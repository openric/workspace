<?php

/*
 * OpenRiC Workspace - copied from Heratio, packages/ahg-semantic-search/src/Services/ScholarshipService.php
 * (discover, flattenGroups, secondHop, buildPrompt, runAi, parseInsights, shapeConnections, discoverFederated,
 * discoverFederatedCached, accessPointTerms, shapeFederatedConnections, explainFederatedConnections,
 * parseNumberedLines, with their caps) and src/Console/Commands/GenerateDiscoveriesCommand.php (the candidates, most
 * connected first; buildRow, connectionSummary and confidenceScore) at commit bca348f16d70f4a7defb2c6d4923248a88c4068f
 * (governance/CODE_PROVENANCE.md, roadmap phase 17d4): generative scholarship, a model shown only the verified links
 * around a record and told to use nothing else, its leads kept with the links they rest on. Changed: the links are the
 * public relationship graph around the record (Discovery\Graph, two steps), so a discovery never rests on what the
 * public may not see; the call goes through the gateway's chat route with its receipt (phase 17a), and stops where AI
 * is off; a discovery is for staff, a curator making it a research lead; the federated search is the institution's own
 * (phase 16a); the namespace and code style.
 * Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Scholarship;

use App\Ai\AiUnavailable;
use App\Ai\Assist;
use App\Ai\Gateway;
use App\Api\PublicCatalogue;
use App\Discovery\Graph;
use App\Federation\FederatedSearch;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\ScholarshipDiscovery;
use App\Models\ScholarshipFederatedDiscovery;
use App\Models\User;
use App\Relations\RelationService;
use Illuminate\Support\Facades\DB;

class Scholarship
{
    private const FIRST_HOP_CAP = 40;

    private const SECOND_HOP_CAP = 30;

    private const MAX_INSIGHTS = 8;

    private const FEDERATED_CAP = 15;

    private const FEDERATED_TERM_CAP = 12;

    private const FEDERATED_EXPLAIN_CAP = 8;

    private const FEDERATED_CACHE_MINUTES = 1440;

    private const DOMAINS = ['record' => 'Records', 'agent' => 'People and organisations', 'repository' => 'Repositories', 'place' => 'Places',
        'activity' => 'Activities', 'rule' => 'Rules', 'concept' => 'Subjects'];

    public function __construct(private Gateway $gateway, private RelationService $relations) {}

    /**
     * What the graph around a record shows, and what a model draws from it: the connections grouped by domain, the
     * second step reached through them, and the insights, none where AI is off or answers nothing.
     */
    public function discover(RecordResource $record, ?User $user = null): array
    {
        $catalogue = new PublicCatalogue(Institution::findOrFail($record->institution_id));
        $graph = (new Graph($catalogue->view, $this->relations))->build($record, 2);
        $groups = [];
        $first = [];
        $second = [];
        foreach ($graph->nodes as $iri => $node) {
            if ($node['depth'] === 0) {
                continue;
            }
            $d = $graph->describe($iri);
            $item = ['iri' => $iri, 'name' => $d['label'], 'domain' => self::DOMAINS[$d['kind']] ?? 'Other', 'url' => $d['url']];
            if ($node['depth'] === 1 && count($first) < self::FIRST_HOP_CAP) {
                $first[$iri] = $item;
                $groups[$item['domain']][] = $item;
            } elseif ($node['depth'] === 2 && count($second) < self::SECOND_HOP_CAP) {
                $second[] = $item + ['via' => $graph->describe($node['parent'])['label']];
            }
        }
        $title = (string) $catalogue->view->label($record);
        $connections = array_map(fn ($domain, $items) => ['domain' => $domain, 'count' => count($items), 'items' => $items], array_keys($groups), $groups);
        $result = ['title' => $title, 'connections' => $connections, 'total' => count($first), 'second_hop' => $second, 'second_hop_count' => count($second),
            'grounded_entities' => count($first) + count($second), 'insights' => [], 'ai_available' => false, 'inference_id' => null];
        if ($first === []) {
            return $result;
        }
        try {
            $call = $this->chat($record, $this->prompt($title, $groups, $second), 'You are a careful archival research analyst. You ground every statement in the supplied catalogue links and never invent entities or facts.', 'scholarship', $user);
        } catch (AiUnavailable) {
            return $result;
        }

        return [...$result, 'insights' => array_slice($this->insights($call['read']['output']), 0, self::MAX_INSIGHTS), 'ai_available' => true, 'inference_id' => $call['inference']->id];
    }

    /** Discover around a record and keep it, Heratio's buildRow. */
    public function persist(RecordResource $record, ?User $user = null): ?ScholarshipDiscovery
    {
        $d = $this->discover($record, $user);
        if ($d['total'] === 0) {
            return null;
        }
        $insights = array_values(array_filter($d['insights']));

        return ScholarshipDiscovery::updateOrCreate(['record_resource_id' => $record->id], ['institution_id' => $record->institution_id, 'title' => mb_substr($d['title'], 0, 1024),
            'summary' => $insights ? implode("\n", $insights) : $this->connectionSummary($d['total'], $d['second_hop_count']), 'connection_count' => $d['total'],
            'confidence' => self::confidence($d['total'], $d['second_hop_count'], $d['grounded_entities'], count($insights)),
            'evidence' => ['connections' => $d['connections'], 'second_hop' => $d['second_hop'], 'grounded_entities' => $d['grounded_entities'], 'second_hop_count' => $d['second_hop_count'],
                'ai_available' => $d['ai_available'], 'insights' => $insights],
            'inference_id' => $d['inference_id'], 'generated_at' => now()]);
    }

    /**
     * Refresh the discoveries of the institution's published records, the most connected first (Heratio's
     * ahg:generate-discoveries), at most $limit.
     *
     * @return array{examined: int, stored: int, with_lead: int, connections_only: int, skipped: int, ai_reached: bool}
     */
    public function generate(Institution $institution, int $limit = 25, ?User $user = null): array
    {
        $out = ['examined' => 0, 'stored' => 0, 'with_lead' => 0, 'connections_only' => 0, 'skipped' => 0, 'ai_reached' => false];
        foreach ($this->candidates($institution, max(1, min($limit, 200))) as $record) {
            $out['examined']++;
            $discovery = $this->persist($record, $user);
            if ($discovery === null) {
                $out['skipped']++;

                continue;
            }
            $out['stored']++;
            $discovery->evidence['insights'] ? $out['with_lead']++ : $out['connections_only']++;
            $out['ai_reached'] = $out['ai_reached'] || $discovery->evidence['ai_available'];
        }

        return $out;
    }

    /** Heratio's evidence score: the links, the entities grounded, a second step and a lead, from 5 to 100. */
    public static function confidence(int $total, int $secondHop, int $grounded, int $insights): int
    {
        return (int) max(5, min(100, round(($total + $grounded + ($secondHop > 0 ? 8 : 0) + ($insights > 0 ? 6 : 0)) * 2)));
    }

    /** @return array{label: string, level: string} */
    public static function band(int $score): array
    {
        return $score >= 70 ? ['label' => 'High confidence', 'level' => 'high'] : ($score >= 40 ? ['label' => 'Moderate confidence', 'level' => 'moderate'] : ['label' => 'Tentative', 'level' => 'tentative']);
    }

    /**
     * What the institution's federation peers hold that shares the record's access points, each link explained by a
     * model from those shared terms alone; kept for a day, and the last kept answer given when the peers do not answer.
     */
    public function federated(RecordResource $record, bool $refresh = false, ?User $user = null): array
    {
        $cached = ScholarshipFederatedDiscovery::where('record_resource_id', $record->id)->first();
        if (! $refresh && $cached && $cached->generated_at->diffInMinutes(now()) < self::FEDERATED_CACHE_MINUTES) {
            return $this->fromCache($cached);
        }
        $institution = Institution::findOrFail($record->institution_id);
        $catalogue = new PublicCatalogue($institution);
        $title = (string) $catalogue->view->label($record);
        $terms = $this->accessPointTerms($record, $title);
        $live = ['terms' => $terms, 'connections' => [], 'peer_stats' => [], 'ai_available' => false, 'inference_id' => null];
        if ($terms !== []) {
            // Any shared access point, not all at once: the search reads "or" as an alternative (websearch_to_tsquery).
            $found = app(FederatedSearch::class)->search($institution, implode(' or ', array_map(fn ($t) => '"'.str_replace('"', '', $t).'"', $terms)), $user);
            $live['peer_stats'] = array_map(fn ($p) => ['name' => $p['peer']->name, 'status' => $p['status'], 'count' => $p['count'], 'verification' => $p['verification']], $found['peers']);
            $live['connections'] = $this->shapeFederated($found['results'], $terms);
            $live = $this->explainFederated($record, $title, $live, $user);
        }
        if ($live['connections'] !== []) {
            $cached = ScholarshipFederatedDiscovery::updateOrCreate(['record_resource_id' => $record->id], ['institution_id' => $record->institution_id, 'terms' => $live['terms'],
                'connections' => array_slice($live['connections'], 0, self::FEDERATED_CAP), 'peer_stats' => $live['peer_stats'], 'connection_count' => count($live['connections']),
                'ai_available' => $live['ai_available'], 'inference_id' => $live['inference_id'], 'generated_at' => now()]);

            return $this->fromCache($cached) + ['stale' => false];
        }

        return $cached ? $this->fromCache($cached) + ['stale' => true] : [...$live, 'cached' => false, 'generated_at' => null];
    }

    /** The prompt Heratio's buildPrompt writes. */
    private function prompt(string $title, array $groups, array $second): string
    {
        $lines = ['You are a research analyst working inside an archival catalogue. Your task is to surface NON-OBVIOUS connections and concrete research leads for one record, using ONLY the catalogue links supplied below.', '',
            'HARD CONSTRAINTS - you MUST obey all of these:', '1. Use ONLY the entities listed below. Do NOT introduce any person, place, organisation, date, event or record that is not in the lists.',
            '2. NEVER invent facts, names or dates. If you are unsure, say nothing.', '3. Refer to every entity by its EXACT name as written below.',
            '4. Focus on links that a cataloguer might NOT have spotted: shared agents, shared places, shared activities, or two records reachable through the same intermediary. Prefer indirect/second-hop links over restating direct ones.',
            '5. Each lead must be concrete and actionable, e.g. "X and Y were both produced by Z - worth comparing" or "follow A through B to reach C".', '',
            'THE RECORD: "'.$title.'".', '', 'DIRECTLY CONNECTED ENTITIES (first hop), grouped by domain:'];
        foreach ($groups as $domain => $items) {
            $lines[] = '- '.$domain.': '.implode('; ', array_column($items, 'name'));
        }
        $lines[] = '';
        if ($second !== []) {
            $lines[] = 'INDIRECTLY CONNECTED ENTITIES (second hop - reached THROUGH a directly connected entity; the intermediary is shown in brackets):';
            foreach ($second as $c) {
                $lines[] = '- '.$c['name'].' ('.$c['domain'].'; via '.$c['via'].')';
            }
            $lines[] = '';
        }
        $lines[] = 'OUTPUT FORMAT:';
        $lines[] = 'Return up to '.self::MAX_INSIGHTS.' insights, one per line, each starting with "- ". No preamble, no numbering, no closing remarks. If the supplied links reveal nothing non-obvious, return a single line: "- No non-obvious connections found in the supplied links."';

        return implode("\n", $lines);
    }

    /** Heratio's parseInsights: one per line, its bullet taken off, the "nothing found" line dropped. @return list<string> */
    private function insights(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim((string) preg_replace('/^\s*(?:[-*\x{2022}]|\d+[.)])\s*/u', '', trim($line)));
            if ($line !== '' && ! preg_match('/^no non-obvious connections found/i', $line)) {
                $out[] = $line;
            }
        }

        return $out;
    }

    private function connectionSummary(int $total, int $secondHop): string
    {
        $parts = [$total === 1 ? 'This record has 1 verified catalogue connection.' : 'This record has '.$total.' verified catalogue connections.'];
        if ($secondHop > 0) {
            $parts[] = $secondHop === 1 ? 'One further entity is reachable indirectly through a shared link.' : $secondHop.' further entities are reachable indirectly through shared links.';
        }
        $parts[] = 'No non-obvious AI lead was generated for this record; the verified links still stand.';

        return implode(' ', $parts);
    }

    /** The institution's published records, the most related first. */
    private function candidates(Institution $institution, int $limit)
    {
        $public = DB::table('search_entries')->where(['institution_id' => $institution->id, 'public' => true, 'subject_table' => 'record_resources'])->select('subject_id');
        $degree = DB::query()->fromSub(DB::table('relations')->where('source_table', 'record_resources')->select('source_id as id')
            ->unionAll(DB::table('relations')->where('target_table', 'record_resources')->select('target_id as id')), 'r')
            ->whereIn('id', $public)->groupBy('id')->selectRaw('id, count(*) AS c')->orderByDesc('c')->limit($limit)->pluck('id');

        return RecordResource::whereIn('id', $degree)->get()->sortBy(fn ($r) => $degree->search($r->id))->values();
    }

    /** Heratio's accessPointTerms: the title and the names of what it is linked to, three letters at least, twelve at most. */
    private function accessPointTerms(RecordResource $record, string $title): array
    {
        $terms = [];
        $push = function (?string $t) use (&$terms) {
            $t = trim((string) $t);
            if (mb_strlen($t) >= 3 && ! preg_match('/^#?\d+$/', $t) && ! in_array(mb_strtolower($t), array_map('mb_strtolower', $terms), true)) {
                $terms[] = $t;
            }
        };
        $push($title);
        foreach ($this->discover($record)['connections'] as $group) {
            foreach ($group['items'] as $item) {
                $push($item['name']);
            }
        }

        return array_slice($terms, 0, self::FEDERATED_TERM_CAP);
    }

    /** Heratio's shapeFederatedConnections: each result with the access points it shares, the most shared first. */
    private function shapeFederated(array $rows, array $terms): array
    {
        $byKey = [];
        foreach ($rows as $row) {
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $haystack = mb_strtolower($title.' '.($row['description'] ?? ''));
            $shared = array_values(array_filter($terms, fn ($t) => mb_strpos($haystack, mb_strtolower($t)) !== false));
            $key = mb_strtolower($row['peer'].'|'.preg_replace('/\s+/', ' ', $title));
            if (isset($byKey[$key])) {
                count($shared) > count($byKey[$key]['shared']) && $byKey[$key]['shared'] = $shared;

                continue;
            }
            $byKey[$key] = ['title' => $title, 'url' => $row['url'] ?? null, 'peer_name' => $row['peer'] ?: 'Partner institution', 'shared' => $shared,
                'verification' => $row['verification'] ?? null, 'rationale' => null, 'score' => (float) ($row['score'] ?? 1.0)];
        }
        $connections = array_values($byKey);
        usort($connections, fn ($a, $b) => [count($b['shared']), $b['score']] <=> [count($a['shared']), $a['score']]);

        return array_map(fn ($c) => collect($c)->except('score')->all(), $connections);
    }

    /** Heratio's explainFederatedConnections: one sentence a link, from its shared terms alone. */
    private function explainFederated(RecordResource $record, string $title, array $live, ?User $user): array
    {
        $n = min(self::FEDERATED_EXPLAIN_CAP, count($live['connections']));
        if ($n === 0) {
            return $live;
        }
        $lines = ['You are a research analyst. A record in our archive, "'.$title.'", may relate to records held by OTHER institutions found via federated search. For each numbered candidate below, write ONE short sentence explaining why it likely connects to our record.', '',
            'HARD CONSTRAINTS:', '1. Ground every sentence ONLY in the shared access points listed for that candidate. Do NOT introduce any person, place, date, organisation or fact not given.',
            '2. If a candidate shares no meaningful access point, say "Possible match - shared catalogue terms only" for that number.', '3. Keep each line to one sentence. Refer to shared terms by their exact wording.', '', 'CANDIDATES:'];
        for ($i = 0; $i < $n; $i++) {
            $c = $live['connections'][$i];
            $lines[] = ($i + 1).'. "'.$c['title'].'" (held by '.$c['peer_name'].'). Shared access points: '.($c['shared'] ? implode('; ', $c['shared']) : '(none beyond a catalogue keyword)').'.';
        }
        $lines[] = '';
        $lines[] = 'OUTPUT FORMAT: one line per candidate, in order, each starting with the candidate number and ". ". No preamble, no closing remarks.';
        try {
            $call = $this->chat($record, implode("\n", $lines), 'You are a careful archival research analyst. You ground every statement in the supplied shared catalogue terms and never invent entities or facts.', 'scholarship-federated', $user);
        } catch (AiUnavailable) {
            return $live;
        }
        foreach (preg_split('/\r\n|\r|\n/', $call['read']['output']) as $line) {
            if (preg_match('/^\s*(\d+)\s*[.)]\s*(.+)$/u', trim($line), $m) && (int) $m[1] >= 1 && (int) $m[1] <= $n) {
                $live['connections'][(int) $m[1] - 1]['rationale'] = trim($m[2]);
            }
        }

        return [...$live, 'ai_available' => true, 'inference_id' => $call['inference']->id];
    }

    private function chat(RecordResource $record, string $prompt, string $system, string $field, ?User $user): array
    {
        $institution = Institution::findOrFail($record->institution_id);
        $model = $this->gateway->settings($institution->id)->chat_model ?: (string) config('workspace.ai.chat_model');

        return $this->gateway->call($institution, 'chat', 'ollama/api/chat',
            ['model' => $model, 'stream' => false, 'think' => false, 'options' => ['temperature' => 0.2], 'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $prompt]]],
            ['subject' => $record, 'field' => $field, 'user' => $user, 'input' => $prompt],
            fn (array $answer) => ['output' => Assist::withoutThinking((string) ($answer['message']['content'] ?? '')), 'model' => (string) ($answer['model'] ?? $model)]);
    }

    private function fromCache(ScholarshipFederatedDiscovery $c): array
    {
        return ['terms' => $c->terms, 'connections' => $c->connections, 'peer_stats' => $c->peer_stats, 'ai_available' => $c->ai_available, 'inference_id' => $c->inference_id,
            'cached' => true, 'stale' => $c->generated_at->diffInMinutes(now()) >= self::FEDERATED_CACHE_MINUTES, 'generated_at' => $c->generated_at];
    }
}
