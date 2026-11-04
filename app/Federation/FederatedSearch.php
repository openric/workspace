<?php

// OpenRiC Workspace - federated search (roadmap phase 16a), after Heratio's ahg-federation FederatedSearchService: an
// institution's catalogue answers the peers that search it with what it has published, of the levels it shares, signed;
// and it searches its own peers at once, each within its time limit, an institution on this installation read here and
// any other by its federation API, merges what they answer by score and priority, and keeps each peer's answer for
// fifteen minutes and every search in its log. A peer whose address is not a public one is not asked (Heratio's SSRF
// guard); each answer is verified against the peer's key.
// Changed from Heratio: an institution on the same installation is a peer like any other, and is read as the public
// sees it, so no institution ever sees another's drafts; and a peer's API key is encrypted, never kept as text.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Federation;

use App\Models\Concept;
use App\Models\FederationPeer;
use App\Models\FederationShareSetting;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use App\Search\SearchService;
use App\Support\Vocab;
use App\Webhooks\Webhooks;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class FederatedSearch
{
    public const CACHE_MINUTES = 15;

    public function __construct(private SearchService $search, private Trust $trust) {}

    /**
     * What the institution gives a peer that searches it: its published units of description matching the query, of
     * the levels it shares; nothing where it does not share.
     *
     * @return array{total: int, results: list<array<string, mixed>>, institution: string}|null null where it does not share
     */
    public function answer(Institution $institution, string $query, int $limit = 20): ?array
    {
        $share = FederationShareSetting::for($institution->id);
        if (! $share->share_enabled) {
            return null;
        }
        $limit = max(1, min(100, $limit));
        $levels = array_values(array_filter((array) $share->levels));
        $found = $this->search->search($institution, $query, true, ['kind' => 'records'], 1, $levels === [] ? $limit : 200);
        $units = RecordResource::whereIn('id', array_column($found['results'], 'id'))->whereNull('deleted_at')
            ->when($levels !== [], fn ($q) => $q->whereIn('level_id', $levels))->with('level.labels')->get()->keyBy('id');
        $results = [];
        foreach ($found['results'] as $rank => $hit) {
            if (! ($unit = $units->get($hit['id'])) || count($results) >= $limit) {
                continue;
            }
            $results[] = ['id' => $unit->iri, 'title' => $hit['title'], 'description' => trim(strip_tags((string) $hit['snippet'])) ?: null,
                'referenceCode' => $unit->referenceCode(), 'levelOfDescription' => $unit->level?->label(['en']), 'type' => 'record',
                'url' => $unit->iri, 'score' => round(1 / (1 + $rank), 4)];
        }

        return ['total' => $levels === [] ? $found['total'] : count($results), 'results' => $results, 'institution' => $institution->name];
    }

    /**
     * Search the institution's peers at once and merge what they answer.
     *
     * @return array{results: list<array<string, mixed>>, peers: list<array{peer: FederationPeer, status: string, verification: ?string, count: int, ms: int, error: ?string}>}
     */
    public function search(Institution $institution, string $query, ?User $user = null): array
    {
        $started = microtime(true);
        $hash = hash('sha256', mb_strtolower(trim($query)));
        $peers = FederationPeer::where(['institution_id' => $institution->id, 'is_active' => true, 'search_enabled' => true])->with('type', 'peerInstitution')->orderBy('priority')->get()
            ->filter(fn ($p) => in_array($p->type->notation, ['institution', 'workspace', 'heratio'], true))->values();
        $outcomes = [];
        $remote = [];
        foreach ($peers as $peer) {
            $cached = DB::table('federation_search_cache')->where(['peer_id' => $peer->id, 'query_hash' => $hash])->where('expires_at', '>', now())->first();
            if ($cached) {
                $outcomes[$peer->id] = ['peer' => $peer, 'status' => 'success', 'verification' => $cached->verification, 'results' => json_decode($cached->results, true), 'ms' => 0, 'error' => null, 'cached' => true];
            } elseif ($peer->type->notation === 'institution') {
                $at = microtime(true);
                $answer = $peer->peerInstitution ? $this->answer($peer->peerInstitution, $query, $peer->max_results) : null;
                $outcomes[$peer->id] = ['peer' => $peer, 'status' => $answer === null ? 'error' : 'success', 'verification' => 'local', 'results' => $answer['results'] ?? [],
                    'ms' => (int) ((microtime(true) - $at) * 1000), 'error' => $answer === null ? __('It does not share its catalogue.') : null];
            } elseif (! ($address = $peer->searchAddress()) || ! Webhooks::publicUrl($address)) {
                $outcomes[$peer->id] = ['peer' => $peer, 'status' => 'skipped', 'verification' => null, 'results' => [], 'ms' => 0, 'error' => __('Its address is not a public one.')];
            } else {
                $remote[$peer->id] = [$peer, $address];
            }
        }
        if ($remote !== []) {
            $at = microtime(true);
            $responses = Http::pool(fn (Pool $pool) => collect($remote)->map(fn ($r, $id) => $pool->as($id)->withHeaders(array_filter(['Accept' => 'application/json',
                'User-Agent' => 'OpenRiC-Workspace-Federation/1.0', 'X-API-Key' => $r[0]->api_key]))->timeout(max(1, intdiv($r[0]->timeout_ms, 1000)))->connectTimeout(2)
                ->get($r[1], ['q' => $query, 'limit' => $r[0]->max_results, 'format' => 'json']))->values()->all());
            foreach ($remote as $id => [$peer]) {
                $outcomes[$id] = $this->read($peer, $responses[$id] ?? null, (int) ((microtime(true) - $at) * 1000));
            }
        }

        $results = [];
        foreach ($outcomes as $id => $o) {
            $this->record($o, $hash);
            foreach ($o['results'] as $item) {
                $results[] = ['title' => (string) ($item['title'] ?? $item['name'] ?? __('Untitled')), 'description' => $item['description'] ?? $item['scopeAndContent'] ?? null,
                    'referenceCode' => $item['referenceCode'] ?? $item['identifier'] ?? null, 'level' => $item['levelOfDescription'] ?? $item['level'] ?? null,
                    'url' => $item['url'] ?? $item['permalink'] ?? null, 'id' => $item['id'] ?? $item['url'] ?? null, 'score' => (float) ($item['score'] ?? 1.0),
                    'peer' => $o['peer']->name, 'peer_id' => $id, 'priority' => $o['peer']->priority, 'verification' => $o['verification']];
            }
        }
        // Heratio's merge: by score, then the peer's priority; one row for an item two peers both hold.
        usort($results, fn ($a, $b) => [$b['score'], $a['priority']] <=> [$a['score'], $b['priority']]);
        $results = collect($results)->unique(fn ($r) => $r['id'] ?? Str::uuid()->toString())->values()->all();
        $statuses = collect($outcomes)->countBy('status');
        DB::table('federation_search_log')->insert(['id' => (string) Str::uuid7(), 'institution_id' => $institution->id, 'query_text' => mb_substr($query, 0, 500), 'query_hash' => $hash,
            'user_id' => $user?->id, 'peers_queried' => count($outcomes), 'peers_responded' => $statuses['success'] ?? 0, 'peers_timeout' => $statuses['timeout'] ?? 0,
            'peers_error' => ($statuses['error'] ?? 0) + ($statuses['skipped'] ?? 0), 'total_results' => count($results), 'total_time_ms' => (int) ((microtime(true) - $started) * 1000), 'created_at' => now()]);

        return ['results' => $results, 'peers' => array_values(array_map(fn ($o) => ['peer' => $o['peer'], 'status' => $o['status'], 'verification' => $o['verification'],
            'count' => count($o['results']), 'ms' => $o['ms'], 'error' => $o['error']], $outcomes))];
    }

    /** Remove the cached answers that have expired; the number removed. */
    public function clearExpired(): int
    {
        return DB::table('federation_search_cache')->where('expires_at', '<=', now())->delete();
    }

    /** A remote peer's answer: its results, or how it failed; its signature checked, and a changed key's answer not used. */
    private function read(FederationPeer $peer, mixed $response, int $ms): array
    {
        $fail = fn (string $status, string $error) => ['peer' => $peer, 'status' => $status, 'verification' => null, 'results' => [], 'ms' => $ms, 'error' => $error];
        if ($response instanceof ConnectionException || $response instanceof Throwable) {
            return $fail(str_contains(strtolower($response->getMessage()), 'timed out') ? 'timeout' : 'error', mb_substr($response->getMessage(), 0, 300));
        }
        if (! $response instanceof HttpResponse || ! $response->successful()) {
            return $fail('error', 'HTTP '.($response instanceof HttpResponse ? $response->status() : '?'));
        }
        $data = $response->json();
        if (! is_array($data)) {
            return $fail('error', __('It did not answer in JSON.'));
        }
        $verification = $this->trust->verify($peer, $response->body(), $response->headers());
        if ($verification === 'mismatch') {
            return [...$fail('error', __('It signed with another key than the one pinned for it; its answer is not used until an operator clears the pin.')), 'verification' => 'mismatch'];
        }
        $items = $data['results'] ?? $data['items'] ?? $data['records'] ?? [];

        return ['peer' => $peer, 'status' => 'success', 'verification' => $verification, 'results' => array_slice(is_array($items) ? array_values($items) : [], 0, $peer->max_results), 'ms' => $ms, 'error' => null];
    }

    private function record(array $o, string $hash): void
    {
        $peer = $o['peer'];
        $concept = fn (string $v, ?string $n) => $n ? Concept::where('iri', Vocab::BASE."$v/$n")->value('id') : null;
        $peer->forceFill(['last_search_at' => now(), 'last_search_status_id' => $concept('federation-search-status', $o['status']),
            'last_verification_id' => $concept('federation-verification', $o['verification']) ?? $peer->last_verification_id,
            'avg_response_ms' => empty($o['cached']) && $o['status'] === 'success' ? (int) round((($peer->avg_response_ms ?? $o['ms']) * 4 + $o['ms']) / 5) : $peer->avg_response_ms])->save();
        if ($o['status'] === 'success' && empty($o['cached'])) {
            DB::table('federation_search_cache')->upsert([['id' => (string) Str::uuid7(), 'peer_id' => $peer->id, 'query_hash' => $hash, 'results' => json_encode($o['results']),
                'result_count' => count($o['results']), 'verification' => $o['verification'], 'created_at' => now(), 'expires_at' => now()->addMinutes(self::CACHE_MINUTES)]],
                ['peer_id', 'query_hash'], ['results', 'result_count', 'verification', 'created_at', 'expires_at']);
        }
    }
}
