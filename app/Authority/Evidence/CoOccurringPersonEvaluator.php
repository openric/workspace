<?php

/**
 * CoOccurringPersonEvaluator - Service for Heratio. A place candidate related to an agent named near the mention is a match.
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * This file is part of Heratio, released under the GNU Affero General Public License, version 3 or later.
 *
 * OpenRiC Workspace - copied from Heratio, packages/ahg-authority-resolution/src/Services/Evidence/CoOccurringPersonEvaluator.php
 * at commit bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md, roadmap phase 17b2). Changed: the place's agents are those it has RiC relations with, matched by name to the names near the mention (Facts).
 */

declare(strict_types=1);

namespace App\Authority\Evidence;

use App\Models\AuthorityCandidate;
use App\Models\AuthorityMention;

class CoOccurringPersonEvaluator implements Evaluator
{
    public function dimension(): string
    {
        return 'co_occurring';
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
        $names = Years::values($mention->co_occurring ?? [], AuthorityMention::PERSON_ORG);
        if ($names === []) {
            return Signal::make(Signal::ABSENT, ['reason' => 'no_cooccurring_persons_or_orgs']);
        }
        $related = $facts->associates('places', $candidate->candidate_id);
        $hits = [];
        foreach ($related as $id => $agent) {
            foreach ($names as $name) {
                if (Years::overlap($name, $agent)) {
                    $hits[] = ['agent_id' => $id, 'agent_name' => $agent, 'cooccurring' => $name];
                    break;
                }
            }
        }

        return $hits !== []
            ? Signal::make(Signal::MATCH, ['links' => $hits, 'cooccurring' => $names])
            : Signal::make(Signal::SILENT, ['reason' => $related === [] ? 'no_actor_to_place_links' : 'no_cooccurring_actor_related', 'cooccurring' => $names]);
    }
}
