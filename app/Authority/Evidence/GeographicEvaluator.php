<?php

/**
 * GeographicEvaluator - Service for Heratio. A person or organisation candidate related to, or described with, a place near the mention is a match.
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * This file is part of Heratio, released under the GNU Affero General Public License, version 3 or later.
 *
 * OpenRiC Workspace - copied from Heratio, packages/ahg-authority-resolution/src/Services/Evidence/GeographicEvaluator.php
 * at commit bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md, roadmap phase 17b2). Changed: the candidate's places are the places it has RiC relations with and its ISAAR(CPF) places (Facts).
 */

declare(strict_types=1);

namespace App\Authority\Evidence;

use App\Models\AuthorityCandidate;
use App\Models\AuthorityMention;

class GeographicEvaluator implements Evaluator
{
    public function dimension(): string
    {
        return 'geographic';
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
        $nearby = Years::values($mention->nearby_places ?? [], AuthorityMention::PLACES);
        $known = $facts->agentPlaces($candidate->candidate_id);
        if ($known === [] || $nearby === []) {
            return Signal::make(Signal::ABSENT, ['reason' => $known === [] ? ($nearby === [] ? 'no_candidate_locations_and_no_nearby_places' : 'no_candidate_locations') : 'no_nearby_places',
                'candidate_locations' => $known, 'nearby_places' => $nearby]);
        }
        $overlaps = [];
        foreach ($nearby as $place) {
            foreach ($known as $location) {
                if (Years::overlap($place, $location)) {
                    $overlaps[] = ['mention_place' => $place, 'candidate_location' => $location];
                    break;
                }
            }
        }

        return $overlaps !== []
            ? Signal::make(Signal::MATCH, ['overlaps' => $overlaps, 'candidate_locations' => $known, 'nearby_places' => $nearby])
            : Signal::make(Signal::SILENT, ['reason' => 'no_overlap', 'candidate_locations' => $known, 'nearby_places' => $nearby]);
    }
}
