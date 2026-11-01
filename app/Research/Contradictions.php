<?php

// OpenRiC Workspace - the contradiction engine of a research project (roadmap phase 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ContradictionEngineService: a scan of the project's claims by four rules. Two claims that share at
// least two key terms but stand on opposite sides (one supported, verified or publishable; the other rejected,
// disputed, contested or weak). One source that supports one claim and refutes another. A claim revised into a weak
// status that still carries a high confidence, or whose confidence does not match its stated level. One leading term
// in two opposite claims that otherwise share little (a term used two ways). Each finding is kept once, by a
// signature of its kind and claims, and found again on the next scan; it is dismissed or resolved by the project.
// Heratio's AI deepening (aiDeepen) is AI, in phase 17.
//
// Fixed: Heratio's shared-source rule looks for evidence that "opposes", which its evidence never is (it "refutes"),
// so the rule never fired; and its key terms are split on a-z and 0-9 only, which drops letters such as ê and ë.

namespace App\Research;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\ResearchClaim;
use App\Models\ResearchContradiction;
use App\Models\ResearchProject;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Contradictions
{
    private const POSITIVE = ['supported', 'verified', 'publishable'];

    private const NEGATIVE = ['rejected', 'disputed', 'contested', 'weak'];

    private const STOPWORDS = ['the', 'and', 'but', 'that', 'this', 'with', 'from', 'have', 'were', 'been', 'than', 'then', 'they', 'their', 'which', 'what', 'when', 'will',
        'die', 'het', 'van', 'nie', 'wat', 'was', 'sy', 'hul', 'met', 'vir', 'deur', 'oor', 'maar', 'ook', 'word', 'aan'];

    /** Heratio's levelBand: the confidence a stated level expects. */
    private const BANDS = ['high' => [0.70, 1.00], 'medium' => [0.40, 0.79], 'low' => [0.10, 0.49], 'tentative' => [0.00, 0.29]];

    public function __construct(private Projects $projects) {}

    /** @return array{scanned: int, found: int, by_kind: array<string, int>} */
    public function scan(User $user, ResearchProject $project): array
    {
        $this->projects->must($user, $project, 'contributor');
        $claims = ResearchClaim::where('project_id', $project->id)->with('status', 'confidenceLevel', 'evidence.relationship', 'evidence.sourceType')->get()->values();
        $findings = [...$this->opposing($claims), ...$this->sharedSource($claims), ...$this->confidenceDrop($claims), ...$this->drift($claims)];
        DB::transaction(fn () => AuditLog::actingAs($user, function () use ($project, $findings) {
            foreach ($findings as $f) {
                $signature = $f['kind'].':'.collect([$f['a'], $f['b']])->filter()->sort()->implode('-');
                $existing = $project->contradictions()->where('signature', $signature)->first();
                $fields = ['detail' => $f['detail'], 'severity_id' => $this->concept('contradiction-severity', $f['severity'])];
                $existing ? $existing->update($fields) : $project->contradictions()->create($fields + ['claim_a_id' => $f['a'], 'claim_b_id' => $f['b'], 'signature' => $signature,
                    'kind_id' => $this->concept('contradiction-kind', $f['kind']), 'status_id' => $this->concept('finding-status', 'open'), 'detected_by_id' => $this->concept('contradiction-source', 'heuristic')]);
            }
        }));

        return ['scanned' => $claims->count(), 'found' => count($findings), 'by_kind' => collect($findings)->countBy('kind')->all()];
    }

    public function setStatus(User $user, ResearchContradiction $finding, string $status): void
    {
        $this->projects->must($user, $finding->project, 'contributor');
        in_array($status, ['open', 'dismissed', 'resolved'], true) || throw new InvalidArgumentException(__('Choose a status from the list.'));
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $finding->update(['status_id' => $this->concept('finding-status', $status)])));
    }

    /** Rule (a): opposite sides of one topic. */
    private function opposing(Collection $claims): array
    {
        $out = [];
        foreach ($this->pairs($claims) as [$a, $b]) {
            $overlap = $this->overlap($a, $b);
            if ($overlap >= 2) {
                $out[] = ['kind' => 'opposing-status', 'a' => $a->id, 'b' => $b->id, 'severity' => $overlap >= 4 ? 'high' : 'medium',
                    'detail' => __('These two claims share a topic (:n shared key terms) but hold opposing positions: ":a" is :sa, while ":b" is :sb.',
                        ['n' => $overlap, 'a' => $this->short($a), 'sa' => $a->status->notation, 'b' => $this->short($b), 'sb' => $b->status->notation])];
            }
        }

        return $out;
    }

    /** Rule (b): one source supporting one claim and refuting another. */
    private function sharedSource(Collection $claims): array
    {
        $out = [];
        $bySource = [];
        foreach ($claims as $c) {
            foreach ($c->evidence as $e) {
                $bySource[$e->sourceType->notation.':'.$e->source_id][$e->relationship->notation][$c->id] = $c;
            }
        }
        foreach ($bySource as $key => $sides) {
            foreach ($sides['supports'] ?? [] as $a) {
                foreach ($sides['refutes'] ?? [] as $b) {
                    if ($a->id !== $b->id) {
                        $out[] = ['kind' => 'shared-source-conflict', 'a' => $a->id, 'b' => $b->id, 'severity' => 'high',
                            'detail' => __('The same source (:source) is cited to support ":a" but to refute ":b". One source cannot do both: reconcile the reading of it.',
                                ['source' => str_replace(':', ' ', explode(':', $key)[0]), 'a' => $this->short($a), 'b' => $this->short($b)])];
                    }
                }
            }
        }

        return $out;
    }

    /** Rule (c): a weakened claim still sure of itself, or a confidence that does not match its level. */
    private function confidenceDrop(Collection $claims): array
    {
        $out = [];
        foreach ($claims as $c) {
            if ($c->version > 1 && in_array($c->status->notation, self::NEGATIVE, true) && $c->confidence !== null && $c->confidence >= 0.70) {
                $out[] = ['kind' => 'confidence-drop', 'a' => $c->id, 'b' => null, 'severity' => 'medium',
                    'detail' => __('This claim has been revised (:v versions) and is now :s, yet still carries a high confidence of :c. The confidence has not come down with the status.',
                        ['v' => $c->version, 's' => $c->status->notation, 'c' => number_format($c->confidence, 2)])];
            } elseif ($c->confidence !== null && ($band = self::BANDS[$c->confidenceLevel?->notation] ?? null) && ($c->confidence < $band[0] || $c->confidence > $band[1])) {
                $out[] = ['kind' => 'confidence-drop', 'a' => $c->id, 'b' => null, 'severity' => 'low',
                    'detail' => __('The confidence (:c) does not match the stated level ":l" for ":a". One of the two has drifted.',
                        ['c' => number_format($c->confidence, 2), 'l' => $c->confidenceLevel->notation, 'a' => $this->short($c)])];
            }
        }

        return $out;
    }

    /** Rule (d): one leading term, two opposite claims, little else in common. */
    private function drift(Collection $claims): array
    {
        $out = [];
        foreach ($claims->groupBy(fn ($c) => array_key_first($this->terms($c)) ?? '') as $term => $group) {
            if ($term === '' || $group->count() < 2) {
                continue;
            }
            foreach ($this->pairs($group->values()) as [$a, $b]) {
                if ($this->overlap($a, $b) <= 2) {
                    $out[] = ['kind' => 'definition-drift', 'a' => $a->id, 'b' => $b->id, 'severity' => 'low',
                        'detail' => __('The term ":t" leads two claims that point in opposite directions but otherwise share little wording. It may carry two meanings in the project: check that its definition holds.', ['t' => $term])];
                }
            }
        }

        return $out;
    }

    /** Pairs of claims on opposite sides. */
    private function pairs(Collection $claims): array
    {
        $out = [];
        for ($i = 0; $i < $claims->count(); $i++) {
            for ($j = $i + 1; $j < $claims->count(); $j++) {
                $pa = $this->polarity($claims[$i]);
                $pb = $this->polarity($claims[$j]);
                if ($pa !== 0 && $pb !== 0 && $pa !== $pb) {
                    $out[] = [$claims[$i], $claims[$j]];
                }
            }
        }

        return $out;
    }

    private function polarity(ResearchClaim $c): int
    {
        return in_array($c->status->notation, self::POSITIVE, true) ? 1 : (in_array($c->status->notation, self::NEGATIVE, true) ? -1 : 0);
    }

    private function overlap(ResearchClaim $a, ResearchClaim $b): int
    {
        return count(array_intersect_key($this->terms($a), $this->terms($b)));
    }

    /** Heratio's keywords: words of four letters or more, not stopwords, in order. @return array<string, true> */
    private function terms(ResearchClaim $c): array
    {
        $out = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(trim($c->object_value.' '.$c->subject_label))) ?: [] as $w) {
            if (mb_strlen($w) >= 4 && ! in_array($w, self::STOPWORDS, true)) {
                $out[$w] = true;
            }
        }

        return $out;
    }

    private function short(ResearchClaim $c): string
    {
        return mb_strlen($c->object_value) > 80 ? mb_substr($c->object_value, 0, 79).'…' : $c->object_value;
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.$vocabulary.'/'.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
