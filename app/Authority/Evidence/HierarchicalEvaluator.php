<?php

/**
 * HierarchicalEvaluator - Service for Heratio. A place candidate with a broader place named near the mention is a match.
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * This file is part of Heratio, released under the GNU Affero General Public License, version 3 or later.
 *
 * OpenRiC Workspace - copied from Heratio, packages/ahg-authority-resolution/src/Services/Evidence/HierarchicalEvaluator.php
 * at commit bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md, roadmap phase 17b2). Changed: the broader places are the place's parents in the Workspace (Facts).
 */

declare(strict_types=1);

namespace App\Authority\Evidence;

use App\Models\AuthorityCandidate;
use App\Models\AuthorityMention;

class HierarchicalEvaluator implements Evaluator
{
    public function dimension(): string
    {
        return 'hierarchical';
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
        $nearby = Years::values($mention->nearby_places ?? [], AuthorityMention::PLACES);
        $ancestors = $facts->ancestors($candidate->candidate_id);
        if ($ancestors === [] || $nearby === []) {
            return Signal::make(Signal::ABSENT, ['reason' => $ancestors === [] ? ($nearby === [] ? 'no_ancestors_and_no_nearby_places' : 'candidate_has_no_meaningful_ancestors') : 'no_nearby_places',
                'ancestors' => $ancestors, 'nearby_places' => $nearby]);
        }
        $overlaps = [];
        foreach ($nearby as $place) {
            foreach ($ancestors as $ancestor) {
                if (Years::overlap($place, $ancestor['name'])) {
                    $overlaps[] = ['nearby_place' => $place, 'ancestor_name' => $ancestor['name'], 'ancestor_id' => $ancestor['id']];
                    break;
                }
            }
        }

        return $overlaps !== []
            ? Signal::make(Signal::MATCH, ['overlaps' => $overlaps, 'ancestors' => $ancestors, 'nearby_places' => $nearby])
            : Signal::make(Signal::SILENT, ['reason' => 'no_overlap', 'ancestors' => $ancestors, 'nearby_places' => $nearby]);
    }
}
