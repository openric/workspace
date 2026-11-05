<?php

/**
 * ViafAdapter - Service for Heratio. VIAF's AutoSuggest, for people and organisations.
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * This file is part of Heratio, released under the GNU Affero General Public License, version 3 or later.
 *
 * OpenRiC Workspace - copied from Heratio, packages/ahg-authority-resolution/src/Services/Lookup/Adapters/ViafAdapter.php
 * at commit bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md, roadmap phase 17b2). Changed: the namespace and name, the Workspace's User-Agent, the licence from Settings.
 */

declare(strict_types=1);

namespace App\Authority\Lookup;

use Illuminate\Support\Facades\Http;

class Viaf extends Lookup
{
    private const URL = 'https://viaf.org/viaf/AutoSuggest';

    public function source(): string
    {
        return 'viaf';
    }

    public function supports(string $label): bool
    {
        return in_array($label, ['PERSON', 'ORG'], true);
    }

    protected function fetchFromSource(string $query, string $label, int $limit): array
    {
        $response = Http::timeout(self::HTTP_TIMEOUT)->withHeaders(['Accept' => 'application/json', 'User-Agent' => self::USER_AGENT])->get(self::URL, ['query' => $query]);
        $results = $response->ok() ? ($response->json('result') ?? null) : null;
        if (! is_array($results)) {
            return [];
        }
        [$licence, $licenceUrl] = $this->licence();
        $out = [];
        foreach ($results as $hit) {
            $id = $hit['viafid'] ?? null;
            $name = $hit['term'] ?? $hit['displayForm'] ?? null;
            if (! $id || ! $name) {
                continue;
            }
            $type = strtolower((string) ($hit['nametype'] ?? ''));
            if (($label === 'PERSON' && ! ($type === '' || str_contains($type, 'personal'))) || ($label === 'ORG' && ! str_contains($type, 'corporate'))) {
                continue;
            }
            $dates = self::dates((string) ($hit['term'] ?? ''));
            $out[] = ['source' => 'viaf', 'external_id' => (string) $id, 'external_uri' => 'https://viaf.org/viaf/'.$id.'/', 'authorized_name' => (string) $name,
                'dates_of_existence' => $dates, 'history_snippet' => null,
                'fields' => ['authorized_form_of_name' => (string) $name, 'dates_of_existence' => $dates],
                'licence' => $licence, 'licence_url' => $licenceUrl, 'retrieved_at' => $this->nowIso()];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    private static function dates(string $term): ?string
    {
        return preg_match('/(\d{3,4})\s*-\s*(\d{3,4})/', $term, $m) ? $m[1].'-'.$m[2] : (preg_match('/(\d{3,4})\s*-/', $term, $m) ? $m[1].'-' : null);
    }
}
