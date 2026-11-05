<?php

/**
 * PriorEvaluator - Service for Heratio. A place candidate among the three places the fonds most uses, at least twice, is a match.
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * This file is part of Heratio, released under the GNU Affero General Public License, version 3 or later.
 *
 * OpenRiC Workspace - copied from Heratio, packages/ahg-authority-resolution/src/Services/Evidence/PriorEvaluator.php
 * at commit bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md, roadmap phase 17b2). Changed: the fonds' places are read by DocumentPrior from its RiC place relations and its earlier decisions.
 */

declare(strict_types=1);

namespace App\Authority\Evidence;

use App\Models\AuthorityCandidate;
use App\Models\AuthorityMention;

class PriorEvaluator implements Evaluator
{
    private const TOP_N = 3;

    private const MIN_HITS = 2;

    public function __construct(private DocumentPrior $prior) {}

    public function dimension(): string
    {
        return 'document_prior';
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
        $prior = $this->prior->priorFor($mention->record);
        if ($prior['distribution'] === []) {
            return Signal::make(Signal::ABSENT, ['reason' => 'no_prior_data', 'fonds_id' => $prior['fonds_id'], 'unit_count' => $prior['unit_count']]);
        }
        $top = array_slice($prior['distribution'], 0, self::TOP_N, true);
        $hits = $top[$candidate->candidate_id] ?? 0;

        return $hits >= self::MIN_HITS
            ? Signal::make(Signal::MATCH, ['fonds_id' => $prior['fonds_id'], 'unit_count' => $prior['unit_count'], 'candidate_hits' => $hits, 'top_n' => $top])
            : Signal::make(Signal::SILENT, ['reason' => 'candidate_not_in_top_n', 'fonds_id' => $prior['fonds_id'], 'unit_count' => $prior['unit_count'], 'top_n' => $top]);
    }
}
