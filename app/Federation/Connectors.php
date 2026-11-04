<?php

// OpenRiC Workspace - federated search of catalogues that speak their own protocol (roadmap phase 16b), after Heratio's
// ahg-federation DSpaceConnector and AlmaConnector: a DSpace 7 or later repository by its REST discovery API (items'
// dc.title, dc.description.abstract, dc.identifier.uri, dc.date.issued, dc.contributor.author), and an Ex Libris Alma
// catalogue by SRU 1.2 in MARCXML (245 title, 100/110/700 author, 264/260 or 008 date, 520 summary, 001 MMS id, its
// link from the peer's record address template). Each answer is in the shape a federation peer gives.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Federation;

use App\Models\FederationPeer;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class Connectors
{
    private const MARC = 'http://www.loc.gov/MARC21/slim';

    /** @return list<array<string, mixed>> */
    public function search(FederationPeer $peer, string $query): array
    {
        return match ($peer->type->notation) {
            'dspace' => $this->dspace($peer, $query),
            'alma' => $this->alma($peer, $query),
        };
    }

    /** @return list<array<string, mixed>> */
    private function dspace(FederationPeer $peer, string $query): array
    {
        $base = rtrim((string) ($peer->config['rest_base_url'] ?? $peer->base_url), '/');
        $rest = str_contains($base, '/server/api') ? $base : $base.'/server/api';
        $response = Http::timeout(max(1, intdiv($peer->timeout_ms, 1000)))->connectTimeout(2)->acceptJson()
            ->withHeaders(array_filter(['X-API-Key' => $peer->api_key, 'User-Agent' => 'OpenRiC-Workspace-Federation/1.0']))
            ->get($rest.'/discover/search/objects', ['query' => $query, 'size' => max(1, min($peer->max_results, 100)), 'dsoType' => 'item']);
        $response->successful() || throw new RuntimeException('HTTP '.$response->status());
        // DSpace's metadata keys have dots in them (dc.title), so they are read as keys, not as paths.
        $md = fn (array $item, string $key) => trim((string) ($item['metadata'][$key][0]['value'] ?? '')) ?: null;
        $frontend = preg_replace('#/server/api/?$#', '', $rest);
        $out = [];
        foreach ((array) data_get($response->json(), '_embedded.searchResult._embedded.objects', []) as $i => $wrapper) {
            $item = data_get($wrapper, '_embedded.indexableObject');
            if (! is_array($item) || ! ($uuid = (string) ($item['uuid'] ?? $item['id'] ?? ''))) {
                continue;
            }
            $handle = (string) ($item['handle'] ?? '');
            $out[] = ['id' => $handle !== '' ? 'hdl:'.$handle : 'dspace:'.$uuid, 'title' => $md($item, 'dc.title') ?? (string) ($item['name'] ?? __('Untitled')),
                'description' => mb_substr((string) ($md($item, 'dc.description.abstract') ?? $md($item, 'dc.description') ?? ''), 0, 280) ?: null,
                'url' => $md($item, 'dc.identifier.uri') ?? ($handle !== '' ? $frontend.'/handle/'.$handle : null), 'date' => $md($item, 'dc.date.issued'),
                'creator' => $md($item, 'dc.contributor.author'), 'type' => 'item', 'score' => max(0.1, 1.0 - $i * 0.02)];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function alma(FederationPeer $peer, string $query): array
    {
        $response = Http::timeout(max(1, intdiv($peer->timeout_ms, 1000)))->connectTimeout(2)->accept('application/xml')
            ->withHeaders(['User-Agent' => 'OpenRiC-Workspace-Federation/1.0'])
            ->get(rtrim((string) $peer->base_url, '/'), ['version' => '1.2', 'operation' => 'searchRetrieve', 'recordSchema' => 'marcxml',
                'maximumRecords' => max(1, min($peer->max_results, 50)), 'query' => 'alma.all_for_ui="'.str_replace('"', ' ', $query).'"']);
        $response->successful() || throw new RuntimeException('HTTP '.$response->status());
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($response->body(), LIBXML_NONET);
        libxml_use_internal_errors($previous);
        $loaded || throw new RuntimeException(__('The peer did not answer in XML.'));
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('marc', self::MARC);
        $field = fn ($rec, string $tag, ?string $code = null) => trim((string) ($code === null
            ? $xp->evaluate("string(marc:controlfield[@tag='$tag'])", $rec)
            : $xp->evaluate("string(marc:datafield[@tag='$tag'][1]/marc:subfield[@code='$code'][1])", $rec))) ?: null;
        $template = $peer->config['record_url_template'] ?? null;
        $out = [];
        foreach ($xp->query('//marc:record') as $i => $rec) {
            $mms = $field($rec, '001');
            $title = trim(rtrim(implode(' ', array_filter([$field($rec, '245', 'a'), $field($rec, '245', 'b')])), ' /:;,.'));
            if ($mms === null || $title === '') {
                continue;
            }
            $year = substr((string) $field($rec, '008'), 7, 4);
            $out[] = ['id' => 'alma:'.$mms, 'title' => $title, 'description' => $field($rec, '520', 'a'),
                'creator' => $field($rec, '100', 'a') ?? $field($rec, '110', 'a') ?? $field($rec, '700', 'a'),
                'date' => $field($rec, '264', 'c') ?? $field($rec, '260', 'c') ?? (preg_match('/^\d{4}$/', $year) ? $year : null),
                'url' => is_string($template) && str_contains($template, '{mms_id}') ? str_replace('{mms_id}', rawurlencode($mms), $template) : null,
                'type' => 'item', 'score' => max(0.1, 1.0 - $i * 0.02)];
        }

        return $out;
    }
}
