<?php

/**
 * TemporalEvaluator - Service for Heratio. A person or organisation candidate whose years of existence take in a year near the mention is a match; one with dates and no such year is silent.
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * This file is part of Heratio, released under the GNU Affero General Public License, version 3 or later.
 *
 * OpenRiC Workspace - copied from Heratio, packages/ahg-authority-resolution/src/Services/Evidence/TemporalEvaluator.php
 * at commit bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md, roadmap phase 17b2). Changed: the candidate's years come from its RiC dates first, else its dates of existence (Facts).
 */

declare(strict_types=1);

namespace App\Authority\Evidence;

use App\Models\AuthorityCandidate;
use App\Models\AuthorityMention;

class TemporalEvaluator implements Evaluator
{
    public function dimension(): string
    {
        return 'temporal';
    }

    public function supports(string $label): bool
    {
        return in_array($label, AuthorityMention::PERSON_ORG, true);
    }

    public function evaluate(AuthorityMention $mention, AuthorityCandidate $candidate, Facts $facts): array
    {
        if ($candidate->candidate_table !== 'agents') {
            return Signal::make(Signal::ABSENT, ['reason' => 'candidate_not_an_agent']);
        }
        ['span' => $span, 'text' => $text] = $facts->years('agents', $candidate->candidate_id);
        $nearby = Years::nearby($mention->nearby_dates ?? []);
        if ($span === null || $nearby === []) {
            return Signal::make(Signal::ABSENT, ['reason' => $span === null ? ($nearby === [] ? 'no_candidate_dates_and_no_nearby_dates' : 'no_candidate_dates') : 'no_nearby_dates',
                'candidate_span' => $span, 'candidate_text' => $text, 'nearby_years' => $nearby]);
        }
        $overlaps = array_values(array_filter($nearby, fn ($y) => $y >= $span['start'] && $y <= $span['end']));

        return $overlaps !== []
            ? Signal::make(Signal::MATCH, ['candidate_span' => $span, 'candidate_text' => $text, 'overlapping_years' => $overlaps, 'nearby_years' => $nearby])
            : Signal::make(Signal::SILENT, ['candidate_span' => $span, 'candidate_text' => $text, 'nearby_years' => $nearby, 'reason' => 'no_year_overlap']);
    }
}
