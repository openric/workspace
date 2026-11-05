<?php

/**
 * PlaceConflictEvaluator - Service for Heratio. A place candidate whose dates end before a year near the mention is a conflict.
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * This file is part of Heratio, released under the GNU Affero General Public License, version 3 or later.
 *
 * OpenRiC Workspace - copied from Heratio, packages/ahg-authority-resolution/src/Services/Evidence/PlaceConflictEvaluator.php
 * at commit bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md, roadmap phase 17b2). Changed: Heratio's is a stub, as AtoM's places have no dates; a Workspace place may have RiC dates, so they are compared as the person evaluator compares an agent's.
 */

declare(strict_types=1);

namespace App\Authority\Evidence;

use App\Models\AuthorityCandidate;
use App\Models\AuthorityMention;

class PlaceConflictEvaluator implements Evaluator
{
    public function dimension(): string
    {
        return 'conflict';
    }

    public function supports(string $label): bool
    {
        return in_array($label, AuthorityMention::PLACES, true);
    }

    public function evaluate(AuthorityMention $mention, AuthorityCandidate $candidate, Facts $facts): array
    {
        if ($candidate->candidate_table !== 'places') {
            return Signal::make(Signal::ABSENT, ['reason' => 'candidate_not_a_place']);
        }
        ['span' => $span] = $facts->years('places', $candidate->candidate_id);
        if ($span === null) {
            return Signal::make(Signal::SILENT, ['reason' => 'place_has_no_dates']);
        }
        $after = array_values(array_filter(Years::nearby($mention->nearby_dates ?? []), fn ($y) => $y > $span['end']));

        return $after !== []
            ? Signal::make(Signal::CONFLICT, ['candidate_span' => $span, 'mention_years_after_end' => $after])
            : Signal::make(Signal::SILENT, ['reason' => 'no_temporal_conflict', 'candidate_span' => $span]);
    }
}
