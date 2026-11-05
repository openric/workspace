<?php

/*
 * OpenRiC Workspace - copied from Heratio, packages/ahg-semantic-search/src/Services/FederatedEndangeredService.php
 * (globalRegister, parsePeerRegister, the merge and its order), EndangeredInboundService.php (ingest, pending,
 * statusCounts, review, acceptedForBoard) and packages/ahg-api/src/Controllers/EndangeredApiController.php (index:
 * the published register as a peer reads it, signed; inbound: a known peer's push, its signature checked, refused
 * where the institution requires one) at commit bca348f16d70f4a7defb2c6d4923248a88c4068f
 * (governance/CODE_PROVENANCE.md, roadmap phase 17d3). Changed: the institution's own federation peers, its trust
 * levels, allowed surfaces and requirement of a verified signature (phase 16), and Trust for signing and checking; a
 * peer whose address is not public is not asked; the register answers and pushes are accepted only where the
 * institution allows; risks, urgencies and statuses travel in Heratio's codes (digitisation_gap, in_progress) and are
 * kept as the Workspace's vocabulary rows; the namespace and code style.
 * Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Heritage;

use App\Api\PublicCatalogue;
use App\Audit\AuditLog;
use App\Federation\Trust;
use App\Models\Concept;
use App\Models\EndangeredInboundFlag;
use App\Models\EndangeredItem;
use App\Models\EndangeredSetting;
use App\Models\FederationPeer;
use App\Models\FederationShareSetting;
use App\Models\Institution;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use App\Webhooks\Webhooks;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

class EndangeredNetwork
{
    public const SURFACE = 'endangered';

    public const REVIEW = Vocab::BASE.'inbound-flag-status/';

    private const MAX_ITEMS = 500;

    public function __construct(private Endangered $endangered, private Trust $trust) {}

    /** A notation as Heratio sends it, and back. */
    public static function wire(string $notation): string
    {
        return str_replace('-', '_', $notation);
    }

    public static function notation(?string $wire): string
    {
        return str_replace('_', '-', strtolower(trim((string) $wire)));
    }

    /** One flag of the published register as a peer reads it (Heratio's shapeRow). */
    public function row(EndangeredItem $item, PublicCatalogue $catalogue): array
    {
        $languages = ['en'];

        return ['item_ref' => $item->record->iri, 'title' => EntityKinds::label($item->record, $languages),
            'risk_category' => self::wire($item->risk->notation), 'risk_label' => $item->risk->label($languages),
            'urgency' => self::wire($item->urgency->notation), 'urgency_label' => $item->urgency->label($languages),
            'capture_status' => self::wire($item->captureStatus->notation), 'capture_status_label' => $item->captureStatus->label($languages),
            'reason' => $item->reason, 'priority_score' => Endangered::score($item->urgency->notation, $item->risk->notation, $item->captureStatus->notation),
            'flagged_at' => $item->created_at?->toIso8601String(), 'updated_at' => $item->updated_at?->toIso8601String(),
            'catalogue_url' => route('discover.record', $item->record_resource_id)];
    }

    /** What a peer reads: the published register, perhaps of one risk, urgency or status (Heratio's /api/v1/endangered). */
    public function answer(Institution $institution, array $filters = [], int $limit = 200): array
    {
        $catalogue = new PublicCatalogue($institution);
        [$risk, $urgency, $status] = [self::notation($filters['risk'] ?? null) ?: null, self::notation($filters['urgency'] ?? null), self::notation($filters['status'] ?? null)];
        $items = $this->endangered->publicRegister($catalogue, $risk)
            ->filter(fn (EndangeredItem $i) => ($urgency === '' || $i->urgency->notation === $urgency) && ($status === '' || $i->captureStatus->notation === $status))
            ->take(max(1, min(1000, $limit)))->map(fn ($i) => $this->row($i, $catalogue))->values()->all();

        return ['feature' => 'endangered-heritage-register', 'generated_at' => now()->toIso8601String(), 'institution' => $institution->name,
            'base_url' => rtrim($institution->base_iri, '/'), 'available' => true, 'count' => count($items),
            'filters' => ['risk' => $filters['risk'] ?? null, 'urgency' => $filters['urgency'] ?? null, 'status' => $filters['status'] ?? null, 'limit' => $limit],
            'register_url' => route('discover.at-risk'), 'items' => $items];
    }

    /**
     * The network board: the institution's published register, every peer that answers, and the pushes a curator
     * accepted, ranked by score, then urgency, then title (Heratio's globalRegister).
     *
     * @return array{items: list<array>, peers: list<array>, warnings: list<string>, local_count: int, pushed_count: int, dropped_unverified: int}
     */
    public function board(Institution $institution): array
    {
        $catalogue = new PublicCatalogue($institution);
        $merged = [];
        foreach ($this->endangered->publicRegister($catalogue) as $item) {
            $merged['local:'.$item->id] = [...$this->row($item, $catalogue), 'institution' => $institution->name, 'source_peer' => null];
        }
        $local = count($merged);
        $warnings = [];
        $stats = [];
        $dropped = 0;
        $requireVerified = (bool) FederationShareSetting::for($institution->id)->require_verified;
        $remote = [];
        foreach ($this->peers($institution) as $peer) {
            if (! ($address = $this->registerAddress($peer)) || ! Webhooks::publicUrl($address)) {
                $warnings[] = __('Peer :name: its address is not a public one.', ['name' => $peer->name]);

                continue;
            }
            $remote[$peer->id] = [$peer, $address];
        }
        if ($remote !== []) {
            $responses = Http::pool(fn (Pool $pool) => collect($remote)->map(fn ($r, $id) => $pool->as($id)->withHeaders(array_filter(['Accept' => 'application/json',
                'User-Agent' => 'OpenRiC-Workspace-Federation/1.0', 'X-API-Key' => $r[0]->api_key]))->timeout(max(1, intdiv($r[0]->timeout_ms, 1000)))->connectTimeout(2)
                ->get($r[1], ['limit' => self::MAX_ITEMS]))->values()->all());
            foreach ($remote as $id => [$peer]) {
                $response = $responses[$id] ?? null;
                $stat = ['name' => $peer->name, 'base_url' => $peer->base_url, 'status' => 'error', 'verification' => null, 'count' => 0];
                if (! $response instanceof HttpResponse || ! $response->successful() || ! is_array($response->json('items'))) {
                    $warnings[] = __('Peer :name: :error', ['name' => $peer->name, 'error' => $response instanceof Throwable ? mb_substr($response->getMessage(), 0, 200)
                        : ($response instanceof HttpResponse ? 'HTTP '.$response->status() : __('no answer'))]);
                    $stats[] = $stat;

                    continue;
                }
                $verification = $this->trust->verify($peer, $response->body(), $response->headers());
                $stat = [...$stat, 'status' => 'success', 'verification' => $verification];
                $rows = $this->parse($response->json(), $peer, $verification);
                // An answer not signed by the pinned key is dropped where the institution, or the peer's trust, requires one.
                if ($verification === 'mismatch' || (($requireVerified || $peer->trustLevel?->notation === 'verified') && ! in_array($verification, ['verified', 'pinned'], true))) {
                    $dropped += count($rows);
                    $warnings[] = __('Peer :name: :n flags not used, as its answer was not signed by its pinned key.', ['name' => $peer->name, 'n' => count($rows)]);
                    $stats[] = $stat;

                    continue;
                }
                foreach ($rows as $row) {
                    $merged['peer:'.$peer->id.':'.$row['item_ref']] ??= $row;
                }
                $stats[] = [...$stat, 'count' => count($rows)];
            }
        }
        $pushed = 0;
        foreach (EndangeredInboundFlag::where(['institution_id' => $institution->id, 'review_status_id' => self::reviewStatus('accepted')])
            ->with('risk.labels', 'urgency.labels', 'captureStatus.labels')->orderByDesc('reviewed_at')->limit(200)->get() as $flag) {
            $key = 'pushed:'.$flag->dedupe_key;
            if (! isset($merged[$key])) {
                $merged[$key] = ['item_ref' => $flag->reference, 'title' => $flag->title, 'risk_category' => self::wire($flag->risk->notation), 'risk_label' => $flag->risk->label(Vocab::languages()),
                    'urgency' => self::wire($flag->urgency->notation), 'urgency_label' => $flag->urgency->label(Vocab::languages()),
                    'capture_status' => self::wire($flag->captureStatus->notation), 'capture_status_label' => $flag->captureStatus->label(Vocab::languages()),
                    'reason' => $flag->reason, 'priority_score' => Endangered::score($flag->urgency->notation, $flag->risk->notation, $flag->captureStatus->notation),
                    'flagged_at' => $flag->received_at->toIso8601String(), 'catalogue_url' => $flag->catalogue_url, 'institution' => $flag->source_peer_name ?? $flag->source_peer_base_url,
                    'source_peer' => ['name' => $flag->source_peer_name ?? $flag->source_peer_base_url, 'base_url' => $flag->source_peer_base_url, 'verification' => $flag->peer_verified ? 'verified' : 'unsigned'],
                    'pushed' => true];
                $pushed++;
            }
        }
        $items = array_values($merged);
        usort($items, fn ($a, $b) => [-$a['priority_score'], -(Endangered::WEIGHTS[self::notation($a['urgency'])] ?? 10), mb_strtolower((string) $a['title'])]
            <=> [-$b['priority_score'], -(Endangered::WEIGHTS[self::notation($b['urgency'])] ?? 10), mb_strtolower((string) $b['title'])]);

        return ['items' => array_slice($items, 0, self::MAX_ITEMS), 'peers' => $stats, 'warnings' => $warnings, 'local_count' => $local, 'pushed_count' => $pushed, 'dropped_unverified' => $dropped];
    }

    /**
     * A flag a peer pushed (Heratio's EndangeredApiController::inbound and EndangeredInboundService::ingest): from an
     * active peer of the institution allowed the surface, its signature checked against the pinned key and required
     * where the institution requires one; kept for review, a repeated push updating it.
     *
     * @return array{accepted: bool, reason?: string, status: int, review_status?: string, verified?: bool, id?: string}
     */
    public function ingest(Institution $institution, string $peerBaseUrl, string $body, array $headers): array
    {
        $deny = fn (string $reason, int $status = 422) => ['accepted' => false, 'reason' => $reason, 'status' => $status];
        if (! EndangeredSetting::for($institution->id)->accept_pushes) {
            return $deny('this institution does not accept pushed flags', 403);
        }
        $base = rtrim(mb_strtolower(trim($peerBaseUrl)), '/');
        $peer = $base === '' ? null : $this->peers($institution)->first(fn (FederationPeer $p) => rtrim(mb_strtolower((string) $p->base_url), '/') === $base);
        if ($peer === null) {
            return $deny('unknown peer (not a federation peer of this institution allowed the endangered surface)', 403);
        }
        $verification = $this->trust->verify($peer, $body, $headers);
        $verified = in_array($verification, ['verified', 'pinned'], true);
        if ($verification === 'mismatch' || (! $verified && ((bool) FederationShareSetting::for($institution->id)->require_verified || $peer->trustLevel?->notation === 'verified'))) {
            return $deny('signature required: '.$verification, 401);
        }
        $json = json_decode($body, true);
        $item = is_array($json) ? (is_array($json['item'] ?? null) ? $json['item'] : (is_array($json['items'][0] ?? null) ? $json['items'][0]
            : (isset($json['item_ref']) || isset($json['reference']) ? $json : []))) : [];
        $reference = trim((string) ($item['item_ref'] ?? $item['reference'] ?? ''));
        if ($reference === '') {
            return $deny('no flag payload');
        }
        $concept = fn (string $vocabulary, ?string $wire, string $default) => Concept::where('iri', $vocabulary.(self::notation($wire) ?: $default))->value('id')
            ?? Concept::where('iri', $vocabulary.$default)->value('id');
        $dedupe = hash('sha256', $base.'|'.$reference);
        $values = ['peer_id' => $peer->id, 'source_peer_base_url' => mb_substr($peerBaseUrl, 0, 1024), 'source_peer_name' => mb_substr($peer->name, 0, 512),
            'reference' => mb_substr($reference, 0, 512), 'title' => isset($item['title']) ? mb_substr((string) $item['title'], 0, 1024) : null,
            'risk_id' => $concept(Endangered::RISKS, $item['risk_category'] ?? $item['risk'] ?? null, 'other'), 'urgency_id' => $concept(Endangered::URGENCIES, $item['urgency'] ?? null, 'medium'),
            'capture_status_id' => $concept(Endangered::CAPTURE, $item['capture_status'] ?? null, 'flagged'), 'reason' => isset($item['reason']) ? (string) $item['reason'] : null,
            'catalogue_url' => isset($item['catalogue_url']) && filter_var($item['catalogue_url'], FILTER_VALIDATE_URL) ? mb_substr((string) $item['catalogue_url'], 0, 1024) : null,
            'payload' => $item, 'peer_verified' => $verified, 'key_fingerprint' => $peer->pinned_key_id ? mb_substr((string) $peer->pinned_key_id, 0, 128) : null];
        $flag = DB::transaction(function () use ($institution, $dedupe, $values) {
            $flag = EndangeredInboundFlag::firstOrNew(['institution_id' => $institution->id, 'dedupe_key' => $dedupe],
                ['review_status_id' => self::reviewStatus('pending'), 'received_at' => now()]);
            $flag->fill($values)->save();

            return $flag;
        });

        return ['accepted' => true, 'status' => 200, 'review_status' => $flag->reviewStatus->notation, 'verified' => $verified, 'id' => $flag->id];
    }

    public function pending(Institution $institution): Collection
    {
        return EndangeredInboundFlag::where(['institution_id' => $institution->id, 'review_status_id' => self::reviewStatus('pending')])
            ->with('risk.labels', 'urgency.labels', 'captureStatus.labels')->orderByDesc('received_at')->limit(200)->get();
    }

    /** @return array<string,int> */
    public function reviewCounts(Institution $institution): array
    {
        return EndangeredInboundFlag::where('endangered_inbound_flags.institution_id', $institution->id)->join('concepts', 'concepts.id', '=', 'endangered_inbound_flags.review_status_id')
            ->groupBy('concepts.iri')->selectRaw('concepts.iri, count(*) AS c')->pluck('c', 'iri')
            ->mapWithKeys(fn ($c, $iri) => [substr($iri, strlen(self::REVIEW)) => (int) $c])->all();
    }

    public function review(EndangeredInboundFlag $flag, string $decision, User $user): void
    {
        in_array($decision, ['accepted', 'declined'], true) || throw new InvalidArgumentException(__('Accept or decline it.'));
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $flag->update(['review_status_id' => self::reviewStatus($decision), 'reviewed_by_id' => $user->id, 'reviewed_at' => now()])));
    }

    public static function reviewStatus(string $notation): string
    {
        return Concept::where('iri', self::REVIEW.$notation)->valueOrFail('id');
    }

    /** The institution's active peers that may be asked for, or push to, the register: trusted and allowed the surface. */
    private function peers(Institution $institution): Collection
    {
        return FederationPeer::where(['institution_id' => $institution->id, 'is_active' => true])->with('type', 'trustLevel')->orderBy('priority')->get()
            ->filter(fn (FederationPeer $p) => in_array($p->type->notation, ['workspace', 'heratio'], true) && $p->trustLevel?->notation !== 'untrusted'
                && ($p->allowed_surfaces === null || in_array(self::SURFACE, (array) $p->allowed_surfaces, true)))->values();
    }

    /** Where a peer's register is read: Heratio's /api/v1/endangered, a Workspace's /federation/endangered. */
    private function registerAddress(FederationPeer $peer): ?string
    {
        return $peer->base_url ? rtrim($peer->base_url, '/').($peer->type->notation === 'heratio' ? '/api/v1/endangered' : '/federation/endangered') : null;
    }

    /** A peer's register as rows of the board (Heratio's parsePeerRegister). */
    private function parse(array $decoded, FederationPeer $peer, ?string $verification): array
    {
        $institution = is_string($decoded['institution'] ?? null) && $decoded['institution'] !== '' ? $decoded['institution'] : $peer->name;
        $rows = [];
        foreach ((array) $decoded['items'] as $it) {
            if (! is_array($it) || trim((string) ($it['item_ref'] ?? '')) === '') {
                continue;
            }
            [$risk, $urgency, $status] = [self::notation($it['risk_category'] ?? 'other'), self::notation($it['urgency'] ?? 'medium'), self::notation($it['capture_status'] ?? 'flagged')];
            $rows[] = ['item_ref' => (string) $it['item_ref'], 'title' => isset($it['title']) ? (string) $it['title'] : null, 'risk_category' => self::wire($risk),
                'risk_label' => (string) ($it['risk_label'] ?? $risk), 'urgency' => self::wire($urgency), 'urgency_label' => (string) ($it['urgency_label'] ?? $urgency),
                'capture_status' => self::wire($status), 'capture_status_label' => (string) ($it['capture_status_label'] ?? $status), 'reason' => isset($it['reason']) ? (string) $it['reason'] : null,
                'priority_score' => is_numeric($it['priority_score'] ?? null) ? (int) $it['priority_score'] : Endangered::score($urgency, $risk, $status),
                'flagged_at' => $it['flagged_at'] ?? null, 'catalogue_url' => is_string($it['catalogue_url'] ?? null) && filter_var($it['catalogue_url'], FILTER_VALIDATE_URL) ? $it['catalogue_url'] : null,
                'institution' => $institution, 'source_peer' => ['name' => $peer->name, 'base_url' => $peer->base_url, 'verification' => $verification]];
        }

        return $rows;
    }
}
