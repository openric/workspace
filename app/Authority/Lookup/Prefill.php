<?php

/**
 * PrefillEngine - Service for Heratio. The fields of a new authority record or place, filled from the outside
 * authorities that know the name, the first in the institution's order winning each field, each field with where it
 * came from; the name itself from the mention, and an agent's history from what was proposed or the passage it was
 * found in when no source has one.
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * This file is part of Heratio, released under the GNU Affero General Public License, version 3 or later.
 *
 * OpenRiC Workspace - copied from Heratio, packages/ahg-authority-resolution/src/Services/Lookup/PrefillEngine.php
 * at commit bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md, roadmap phase 17b2). Changed:
 * the precedence and the sources switched on are the institution's; what the proposal brought (Heratio's
 * ahg_ner_entity_evidence.authority) is the proposal's authority; the Workspace's models.
 */

declare(strict_types=1);

namespace App\Authority\Lookup;

use App\Authority\Settings;
use App\Models\AuthorityMention;

class Prefill
{
    private const SOURCES = [Viaf::class, Wikidata::class, GeoNames::class, Tgn::class, Gnd::class, Isni::class, Sagnc::class];

    /**
     * @return array{lookup_results: array<string, list<array>>, fields: array<string, mixed>, provenance: array<string, array>}
     */
    public function prefill(AuthorityMention $mention, ?string $onlySource = null, int $perSource = 5): array
    {
        $label = $mention->label();
        $query = trim($mention->value());
        $order = array_keys(app(Settings::class)->sources($mention->institution_id));
        $results = [];
        foreach (self::SOURCES as $class) {
            $lookup = app($class)->for($mention->institution_id);
            if (($onlySource === null || $lookup->source() === $onlySource) && $query !== '' && ($found = $lookup->search($query, $label, $perSource)) !== []) {
                $results[$lookup->source()] = $found;
            }
        }
        $place = $mention->isPlace();
        $nameKey = $place ? 'name' : 'authorized_form_of_name';
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $fields = [$nameKey => $query];
        $provenance = [$nameKey => ['origin' => 'mention', 'source' => null, 'uri' => null, 'licence' => null, 'licence_url' => null, 'retrieved_at' => $now]];
        $applied = [];
        foreach ($order as $rank => $source) {
            $first = $results[$source][0] ?? null;
            if (! $first) {
                continue;
            }
            $given = (array) ($first['fields'] ?? []);
            $given[$nameKey] ??= $first['authorized_name'] ?? null;
            if (! $place) {
                $given['dates_of_existence'] ??= $first['dates_of_existence'] ?? null;
                $given['history'] ??= $first['history_snippet'] ?? null;
            }
            foreach ($given as $key => $value) {
                if ($value === null || $value === '' || (isset($applied[$key]) && $applied[$key] < $rank) || $key === $nameKey) {
                    continue;
                }
                [$fields[$key], $applied[$key]] = [$value, $rank];
                $provenance[$key] = ['origin' => $source, 'source' => $source, 'uri' => $first['external_uri'] ?? null, 'licence' => $first['licence'] ?? null,
                    'licence_url' => $first['licence_url'] ?? null, 'retrieved_at' => $first['retrieved_at'] ?? $now];
            }
        }
        if (! $place) {
            foreach (['dates_of_existence', 'history', 'places'] as $key) {
                if (is_string($proposed = $mention->proposal->authority[$key] ?? null) && trim($proposed) !== '') {
                    [$fields[$key], $provenance[$key]] = [$proposed, ['origin' => 'proposal', 'source' => null, 'uri' => null, 'licence' => null, 'licence_url' => null, 'retrieved_at' => $now]];
                }
            }
            if (($fields['history'] ?? '') === '' && ($mention->text_before || $mention->text_after)) {
                $fields['history'] = trim($mention->text_before.' ['.$query.'] '.$mention->text_after);
                $provenance['history'] = ['origin' => 'mention-context', 'source' => null, 'uri' => null, 'licence' => null, 'licence_url' => null, 'retrieved_at' => $now];
            }
        }

        return ['lookup_results' => $results, 'fields' => $fields, 'provenance' => $provenance];
    }
}
