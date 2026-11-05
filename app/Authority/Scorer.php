<?php

/**
 * EvidenceScorer - Service for Heratio
 * Task-4 orchestrator. Given a mention or a single candidate, dispatches
 * every registered Evaluator that supports the mention's entity_type,
 * collects each evaluator's Signal, writes evidence_signals + evidence_data
 * + composite_score back to ahg_mention_candidate, and re-ranks the
 * mention's candidate list by composite_score (desc).
 * Composite scoring:
 *   composite = clamp(name_similarity_score + Sum(weight(signal_i)), 0, 1)
 *   weight(match)    = +0.10
 *   weight(conflict) = -0.30
 *   weight(silent)   =  0.0
 *   weight(absent)   =  0.0
 * Re-ranking: after scoring every candidate for a mention, we re-assign
 * rank_position 1..N in a transaction. Tie-break is name_similarity_score
 * desc, then candidate_display_name asc (deterministic).
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * This file is part of Heratio, released under the GNU Affero General Public License, version 3 or later.
 *
 * OpenRiC Workspace - copied from Heratio, packages/ahg-authority-resolution/src/Services/EvidenceScorer.php
 * at commit bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md, roadmap phase 17b2). Changed:
 * the evaluators are listed here rather than tagged in a service provider, and share one Facts for the mention; the
 * Workspace's models.
 */

declare(strict_types=1);

namespace App\Authority;

use App\Authority\Evidence\ConflictEvaluator;
use App\Authority\Evidence\CoOccurringPersonEvaluator;
use App\Authority\Evidence\Evaluator;
use App\Authority\Evidence\Facts;
use App\Authority\Evidence\GeographicEvaluator;
use App\Authority\Evidence\HierarchicalEvaluator;
use App\Authority\Evidence\PlaceConflictEvaluator;
use App\Authority\Evidence\PriorEvaluator;
use App\Authority\Evidence\RelationalEvaluator;
use App\Authority\Evidence\RoleEvaluator;
use App\Authority\Evidence\ScaleEvaluator;
use App\Authority\Evidence\Signal;
use App\Authority\Evidence\TemporalEvaluator;
use App\Models\AuthorityCandidate;
use App\Models\AuthorityMention;
use Illuminate\Support\Facades\DB;
use Throwable;

class Scorer
{
    private const EVALUATORS = [TemporalEvaluator::class, GeographicEvaluator::class, RelationalEvaluator::class, RoleEvaluator::class, ConflictEvaluator::class,
        CoOccurringPersonEvaluator::class, HierarchicalEvaluator::class, ScaleEvaluator::class, PriorEvaluator::class, PlaceConflictEvaluator::class];

    /** Score one candidate: every evaluator for the mention's label, the signals and their data kept, and the composite. */
    public function score(AuthorityMention $mention, AuthorityCandidate $candidate, ?Facts $facts = null): AuthorityCandidate
    {
        $facts ??= app(Facts::class);
        $label = $mention->label();
        [$signals, $data] = [[], []];
        foreach (self::EVALUATORS as $class) {
            /** @var Evaluator $evaluator */
            $evaluator = app($class);
            if (! $evaluator->supports($label)) {
                continue;
            }
            try {
                $result = $evaluator->evaluate($mention, $candidate, $facts);
            } catch (Throwable $e) {
                $result = Signal::make(Signal::ABSENT, ['reason' => 'evaluator_threw', 'exception' => $e->getMessage()]);
            }
            $signals[$evaluator->dimension()] = $result['signal'];
            $data[$evaluator->dimension()] = $result['data'];
        }
        $delta = array_sum(array_map(fn ($s) => Signal::WEIGHT[$s] ?? 0.0, $signals));
        $candidate->update(['signals' => $signals, 'evidence' => $data, 'composite' => round(max(0.0, min(1.0, $candidate->name_similarity + $delta)), 4), 'computed_at' => now()]);

        return $candidate;
    }

    /** Score every candidate of the mention, then rank them by composite, name similarity and name. */
    public function scoreAll(AuthorityMention $mention): int
    {
        $mention->loadMissing('proposal.kind', 'proposal.agentType', 'record');
        $facts = app(Facts::class);
        $candidates = $mention->candidates()->get();
        $candidates->each(fn ($c) => $this->score($mention, $c, $facts));
        DB::transaction(fn () => $candidates->sort(fn ($a, $b) => [$b->composite, $b->name_similarity, $a->display_name] <=> [$a->composite, $a->name_similarity, $b->display_name])
            ->values()->each(fn ($c, $i) => $c->update(['rank' => $i + 1])));

        return $candidates->count();
    }
}
