<?php

// OpenRiC Workspace - an appraisal of an accession (PSIS's and Heratio's accession_appraisal): its type, appraiser, value,
// significance, recommendation, summary and notes, and its scored criteria.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'accession_id', 'type_id', 'appraiser_id', 'recorded_by_id', 'monetary_value', 'currency', 'significance_id', 'recommendation_id',
    'summary', 'detailed_notes', 'appraised_on'])]
class AccessionAppraisal extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['appraised_on' => 'immutable_date'];
    }

    public function accession(): BelongsTo
    {
        return $this->belongsTo(Accession::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'type_id');
    }

    public function significance(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'significance_id');
    }

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'recommendation_id');
    }

    public function appraiser(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'appraiser_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }

    public function criteria(): HasMany
    {
        return $this->hasMany(AppraisalCriterion::class, 'appraisal_id')->orderBy('position')->orderBy('created_at');
    }

    /** PSIS's calculateWeightedScore: the weighted average of the scored criteria, to two places; null when none is scored. */
    public function weightedScore(): ?float
    {
        $scored = $this->criteria->whereNotNull('score');
        $weight = $scored->sum(fn ($c) => (float) $c->weight);

        return $weight > 0 ? round($scored->sum(fn ($c) => $c->score * (float) $c->weight) / $weight, 2) : null;
    }
}
