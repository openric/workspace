<?php

/**
 * RelationalEvaluator - Service for Heratio. A person or organisation candidate related to a person or organisation named near the mention is a match.
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * This file is part of Heratio, released under the GNU Affero General Public License, version 3 or later.
 *
 * OpenRiC Workspace - copied from Heratio, packages/ahg-authority-resolution/src/Services/Evidence/RelationalEvaluator.php
 * at commit bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md, roadmap phase 17b2). Changed: the candidate's associates are the agents it has RiC relations with, either way (Facts).
 */

declare(strict_types=1);

namespace App\Authority\Evidence;

use App\Models\AuthorityCandidate;
use App\Models\AuthorityMention;

class RelationalEvaluator implements Evaluator
{
    public function dimension(): string
    {
        return 'relational';
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
        $cooccurring = Years::values($mention->co_occurring ?? [], AuthorityMention::PERSON_ORG);
        $associates = array_values($facts->associates('agents', $candidate->candidate_id));
        if ($associates === [] || $cooccurring === []) {
            return Signal::make(Signal::ABSENT, ['reason' => $associates === [] ? ($cooccurring === [] ? 'no_associates_and_no_cooccurring_persons' : 'no_known_actor_to_actor_associates') : 'no_cooccurring_persons_or_orgs',
                'candidate_associates' => $associates, 'cooccurring' => $cooccurring]);
        }
        $overlaps = [];
        foreach ($cooccurring as $name) {
            foreach ($associates as $associate) {
                if (Years::overlap($name, $associate)) {
                    $overlaps[] = ['cooccurring' => $name, 'candidate_associate' => $associate];
                    break;
                }
            }
        }

        return $overlaps !== []
            ? Signal::make(Signal::MATCH, ['overlaps' => $overlaps, 'candidate_associates' => $associates, 'cooccurring' => $cooccurring])
            : Signal::make(Signal::SILENT, ['reason' => 'no_overlap', 'candidate_associates' => $associates, 'cooccurring' => $cooccurring]);
    }
}
