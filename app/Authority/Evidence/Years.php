<?php

/**
 * EvidenceDateUtil - Service for Heratio. Four-digit years in free text, as a span, and the years of the dates near
 * a mention.
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * This file is part of Heratio, released under the GNU Affero General Public License, version 3 or later.
 *
 * OpenRiC Workspace - copied from Heratio, packages/ahg-authority-resolution/src/Services/Evidence/EvidenceDateUtil.php
 * at commit bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md, roadmap phase 17b2). Changed:
 * the namespace and name; the context arrives decoded, so decodeJsonish() is not needed.
 */

declare(strict_types=1);

namespace App\Authority\Evidence;

final class Years
{
    /** @return array{start:int,end:int}|null */
    public static function parseYearSpan(string $freeText): ?array
    {
        if (! preg_match_all('/\b(1[0-9]{3}|20[0-9]{2})\b/', $freeText, $matches)) {
            return null;
        }
        $years = array_map('intval', $matches[1]);

        return $years === [] ? null : ['start' => min($years), 'end' => max($years)];
    }

    /** @return list<int> sorted unique four-digit years of the dates near the mention */
    public static function nearby(array $nearbyDates): array
    {
        $years = [];
        foreach ($nearbyDates as $row) {
            $value = is_array($row) ? (string) ($row['value'] ?? '') : (string) $row;
            if (preg_match_all('/\b(1[0-9]{3}|20[0-9]{2})\b/', $value, $m)) {
                array_push($years, ...array_map('intval', $m[1]));
            }
        }
        $years = array_values(array_unique($years));
        sort($years);

        return $years;
    }

    /** @return list<string> the distinct values of the given labels in a list of entities */
    public static function values(array $entities, array $labels): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($e) => in_array($e['type'] ?? '', $labels, true) ? (string) ($e['value'] ?? '') : '', $entities))));
    }

    /** Do two names overlap, one within the other or the same, ignoring case? Heratio's test in every evaluator. */
    public static function overlap(string $a, string $b): bool
    {
        [$a, $b] = [mb_strtolower(trim($a)), mb_strtolower(trim($b))];

        return $a !== '' && $b !== '' && ($a === $b || str_contains($a, $b) || str_contains($b, $a));
    }
}
