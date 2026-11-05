<?php

/**
 * ConflictEvaluator - Service for Heratio. A person or organisation candidate whose years of existence end before a year near the mention is a conflict.
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * This file is part of Heratio, released under the GNU Affero General Public License, version 3 or later.
 *
 * OpenRiC Workspace - copied from Heratio, packages/ahg-authority-resolution/src/Services/Evidence/ConflictEvaluator.php
 * at commit bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md, roadmap phase 17b2). Changed: the candidate's years come from its RiC dates first, else its dates of existence (Facts).
 */

declare(strict_types=1);

namespace App\Authority\Evidence;

use App\Models\AuthorityCandidate;
use App\Models\AuthorityMention;

class ConflictEvaluator implements Evaluator
{
    public function dimension(): string
    {
        return 'conflict';
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
        if ($span === null) {
            return Signal::make(Signal::ABSENT, ['reason' => $text ? 'unparseable_candidate_dates' : 'no_candidate_dates', 'candidate_text' => $text]);
        }
        $years = Years::nearby($mention->nearby_dates ?? []);
        if ($years === []) {
            return Signal::make(Signal::ABSENT, ['reason' => 'no_nearby_dates', 'candidate_span' => $span]);
        }
        $after = array_values(array_filter($years, fn ($y) => $y > $span['end']));

        return $after !== []
            ? Signal::make(Signal::CONFLICT, ['candidate_span' => $span, 'candidate_text' => $text, 'mention_years_after_end' => $after])
            : Signal::make(Signal::SILENT, ['reason' => 'no_temporal_conflict', 'candidate_span' => $span, 'nearby_years' => $years]);
    }
}
