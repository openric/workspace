<?php

/**
 * ScaleEvaluator - Service for Heratio. A place candidate whose name is of the same scale as the words around the mention (a city, a district, a country) is a match; a facility where the text speaks of a settlement or a region is a conflict.
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * This file is part of Heratio, released under the GNU Affero General Public License, version 3 or later.
 *
 * OpenRiC Workspace - copied from Heratio, packages/ahg-authority-resolution/src/Services/Evidence/ScaleEvaluator.php
 * at commit bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md, roadmap phase 17b2). Changed: only the namespace and the Workspace's code style.
 */

declare(strict_types=1);

namespace App\Authority\Evidence;

use App\Models\AuthorityCandidate;
use App\Models\AuthorityMention;

class ScaleEvaluator implements Evaluator
{
    private const SCALE_FAMILY = [
        'city' => 'settlement', 'town' => 'settlement', 'village' => 'settlement', 'hamlet' => 'settlement', 'borough' => 'settlement', 'suburb' => 'settlement',
        'port' => 'settlement', 'harbour' => 'settlement', 'harbor' => 'settlement',
        'province' => 'subregion', 'region' => 'subregion', 'district' => 'subregion', 'county' => 'subregion', 'state' => 'subregion', 'shire' => 'subregion', 'prefecture' => 'subregion',
        'country' => 'nation', 'kingdom' => 'nation', 'empire' => 'nation', 'nation' => 'nation', 'republic' => 'nation', 'sultanate' => 'nation',
        'continent' => 'continent',
        'bookshop' => 'facility', 'shop' => 'facility', 'school' => 'facility', 'church' => 'facility', 'cathedral' => 'facility', 'university' => 'facility',
        'library' => 'facility', 'museum' => 'facility', 'hospital' => 'facility',
    ];

    public function dimension(): string
    {
        return 'scale';
    }

    public function supports(string $label): bool
    {
        return in_array($label, AuthorityMention::PLACES, true);
    }

    public function evaluate(AuthorityMention $mention, AuthorityCandidate $candidate, Facts $facts): array
    {
        $textTokens = $this->detectTokens(mb_strtolower(($mention->text_before ?? '').' '.($mention->text_after ?? '')));
        $candTokens = $this->detectTokens(mb_strtolower((string) $candidate->display_name));
        if ($textTokens === []) {
            return Signal::make(Signal::ABSENT, ['reason' => 'no_scale_tokens_in_surrounding_text', 'candidate_tokens' => $candTokens]);
        }
        if ($candTokens === []) {
            return Signal::make(Signal::SILENT, ['reason' => 'candidate_name_has_no_scale_suffix', 'text_tokens' => $textTokens]);
        }
        $textFamilies = array_values(array_unique(array_map(fn ($t) => self::SCALE_FAMILY[$t], $textTokens)));
        $candFamilies = array_values(array_unique(array_map(fn ($t) => self::SCALE_FAMILY[$t], $candTokens)));
        if ($overlap = array_values(array_intersect($textFamilies, $candFamilies))) {
            return Signal::make(Signal::MATCH, ['aligned_families' => $overlap, 'text_tokens' => $textTokens, 'candidate_tokens' => $candTokens]);
        }
        if (in_array('facility', $candFamilies, true) && array_intersect($textFamilies, ['settlement', 'subregion', 'nation', 'continent'])) {
            return Signal::make(Signal::CONFLICT, ['reason' => 'candidate_is_facility_but_text_describes_place', 'text_families' => $textFamilies,
                'candidate_families' => $candFamilies, 'text_tokens' => $textTokens, 'candidate_tokens' => $candTokens]);
        }

        return Signal::make(Signal::SILENT, ['reason' => 'families_differ_without_explicit_contradiction', 'text_families' => $textFamilies, 'candidate_families' => $candFamilies]);
    }

    /** @return list<string> */
    private function detectTokens(string $haystack): array
    {
        return $haystack === '' ? [] : array_values(array_filter(array_keys(self::SCALE_FAMILY),
            fn ($t) => preg_match('/(?<![\p{L}\p{N}])'.preg_quote($t, '/').'(?![\p{L}\p{N}])/u', $haystack) === 1));
    }
}
