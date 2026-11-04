<?php

// OpenRiC Workspace - discovery of federation peers (roadmap phase 16c), after Heratio's ahg-federation
// PeerDiscoveryService: each peer's /open-data/protocol fetched, and whether it answers the Federation Query Protocol,
// its version, the surfaces it declares, its maturity grade where it publishes one (/open-data/maturity) and its
// federation block kept, for the federation page, the network directory and the choice of whom to ask.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Federation;

use App\Models\Concept;
use App\Models\FederationPeer;
use App\Support\Vocab;
use App\Webhooks\Webhooks;
use Illuminate\Support\Facades\Http;
use Throwable;

class Discovery
{
    /** Probe the peer; the discovery status found (ok, unreachable, non-compliant). */
    public function probe(FederationPeer $peer): string
    {
        $base = rtrim((string) $peer->base_url, '/');
        $fields = ['last_probed_at' => now()];
        if ($base === '' || ! Webhooks::publicUrl($base)) {
            return $this->save($peer, 'unreachable', $fields);
        }
        try {
            $response = Http::timeout(10)->acceptJson()->withoutRedirecting()->get($base.'/open-data/protocol');
        } catch (Throwable) {
            return $this->save($peer, 'unreachable', $fields);
        }
        $document = $response->successful() ? $response->json() : null;
        $federation = is_array($document) ? ($document['federation'] ?? null) : null;
        if (! is_array($federation)) {
            return $this->save($peer, $response->successful() ? 'non-compliant' : 'unreachable', $fields);
        }
        // The surfaces a peer declares: its own list, else those its federation block gives an address for.
        $surfaces = array_values(array_filter((array) ($federation['surfaces'] ?? []), 'is_string'))
            ?: array_values(array_keys(array_filter(['search' => $federation['search_url'] ?? null, 'union' => $federation['union_url'] ?? null,
                'vocabularies' => $federation['vocabularies_url'] ?? null, 'graph' => $federation['graph_url'] ?? null])));
        $maturity = rescue(fn () => Http::timeout(10)->acceptJson()->withoutRedirecting()->get($base.'/open-data/maturity')->json(), null, false);
        $grade = is_array($maturity) ? ($maturity['grade'] ?? $maturity['headline']['grade'] ?? $maturity['maturity']['grade'] ?? null) : null;

        return $this->save($peer, 'ok', $fields + ['protocol_version' => (string) ($document['protocol_version'] ?? '') ?: null, 'declared_surfaces' => $surfaces,
            'maturity_grade' => is_scalar($grade) ? (string) $grade : null, 'capabilities' => $federation]);
    }

    /** Probe every peer of the institution, or of every institution; the statuses found, by peer. */
    public function probeAll(?string $institutionId = null): array
    {
        return FederationPeer::whereHas('type', fn ($q) => $q->whereIn('notation', ['workspace', 'heratio']))->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->get()->mapWithKeys(fn ($p) => [$p->name => $this->probe($p)])->all();
    }

    private function save(FederationPeer $peer, string $status, array $fields): string
    {
        $peer->forceFill([...$fields, 'discovery_status_id' => Concept::where('iri', Vocab::BASE.'federation-discovery-status/'.$status)->value('id')])->save();

        return $status;
    }
}
