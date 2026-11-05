<?php

/**
 * WikidataAdapter - Service for Heratio. Wikidata's wbsearchentities, for people, organisations and places.
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * This file is part of Heratio, released under the GNU Affero General Public License, version 3 or later.
 *
 * OpenRiC Workspace - copied from Heratio, packages/ahg-authority-resolution/src/Services/Lookup/Adapters/WikidataAdapter.php
 * at commit bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md, roadmap phase 17b2). Changed: the namespace and name, the Workspace's User-Agent, the licence from Settings.
 */

declare(strict_types=1);

namespace App\Authority\Lookup;

use Illuminate\Support\Facades\Http;

class Wikidata extends Lookup
{
    private const URL = 'https://www.wikidata.org/w/api.php';

    public function source(): string
    {
        return 'wikidata';
    }

    public function supports(string $label): bool
    {
        return in_array($label, ['PERSON', 'ORG', 'GPE', 'LOC', 'PLACE'], true);
    }

    protected function fetchFromSource(string $query, string $label, int $limit): array
    {
        $response = Http::timeout(self::HTTP_TIMEOUT)->withHeaders(['Accept' => 'application/json', 'User-Agent' => self::USER_AGENT])
            ->get(self::URL, ['action' => 'wbsearchentities', 'search' => $query, 'language' => 'en', 'uselang' => 'en', 'type' => 'item', 'format' => 'json', 'limit' => max(1, min(20, $limit + 5))]);
        $hits = $response->ok() ? ($response->json('search') ?? []) : [];
        [$licence, $licenceUrl] = $this->licence();
        $place = in_array($label, ['GPE', 'LOC', 'PLACE'], true);
        $out = [];
        foreach ((array) $hits as $hit) {
            $qid = $hit['id'] ?? null;
            $name = $hit['label'] ?? $hit['title'] ?? null;
            if (! $qid || ! $name) {
                continue;
            }
            $description = $hit['description'] ?? null;
            $out[] = ['source' => 'wikidata', 'external_id' => (string) $qid, 'external_uri' => 'https://www.wikidata.org/wiki/'.$qid, 'authorized_name' => (string) $name,
                'dates_of_existence' => null, 'history_snippet' => $description,
                'fields' => $place ? ['name' => (string) $name, 'description' => $description] : ['authorized_form_of_name' => (string) $name, 'history' => $description],
                'licence' => $licence, 'licence_url' => $licenceUrl, 'retrieved_at' => $this->nowIso()];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
