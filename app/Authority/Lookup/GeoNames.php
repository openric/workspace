<?php

/**
 * GeoNamesAdapter - Service for Heratio. GeoNames' searchJSON, for places, with the institution's registered username.
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * This file is part of Heratio, released under the GNU Affero General Public License, version 3 or later.
 *
 * OpenRiC Workspace - copied from Heratio, packages/ahg-authority-resolution/src/Services/Lookup/Adapters/GeoNamesAdapter.php
 * at commit bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md, roadmap phase 17b2). Changed: the namespace and name, HTTPS, the username from the institution's settings (no 'demo' default, which GeoNames limits).
 */

declare(strict_types=1);

namespace App\Authority\Lookup;

use Illuminate\Support\Facades\Http;

class GeoNames extends Lookup
{
    private const URL = 'https://secure.geonames.org/searchJSON';

    public function source(): string
    {
        return 'geonames';
    }

    public function supports(string $label): bool
    {
        return in_array($label, ['GPE', 'LOC', 'PLACE'], true);
    }

    protected function fetchFromSource(string $query, string $label, int $limit): array
    {
        $username = (string) ($this->settings['username'] ?? '');
        if ($username === '') {
            return [];
        }
        $response = Http::timeout(self::HTTP_TIMEOUT)->withHeaders(['Accept' => 'application/json', 'User-Agent' => self::USER_AGENT])
            ->get(self::URL, ['q' => $query, 'maxRows' => max(1, min(20, $limit + 5)), 'username' => $username, 'style' => 'MEDIUM']);
        $json = $response->ok() ? $response->json() : null;
        if (! is_array($json) || isset($json['status']['message'])) {
            return [];
        }
        [$licence, $licenceUrl] = $this->licence();
        $out = [];
        foreach ((array) ($json['geonames'] ?? []) as $hit) {
            $id = $hit['geonameId'] ?? null;
            $name = $hit['name'] ?? $hit['toponymName'] ?? null;
            if (! $id || ! $name) {
                continue;
            }
            [$country, $admin] = [$hit['countryName'] ?? null, $hit['adminName1'] ?? null];
            $out[] = ['source' => 'geonames', 'external_id' => (string) $id, 'external_uri' => 'https://www.geonames.org/'.$id, 'authorized_name' => (string) $name,
                'dates_of_existence' => null, 'history_snippet' => trim(($admin ? $admin.', ' : '').($country ?? '')) ?: null,
                'fields' => ['name' => (string) $name, 'latitude' => isset($hit['lat']) ? (float) $hit['lat'] : null, 'longitude' => isset($hit['lng']) ? (float) $hit['lng'] : null,
                    'country' => $country, 'admin_region' => $admin, 'feature_class' => $hit['fcl'] ?? null, 'feature_code' => $hit['fcode'] ?? null],
                'licence' => $licence, 'licence_url' => $licenceUrl, 'retrieved_at' => $this->nowIso()];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
