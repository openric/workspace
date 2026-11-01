<?php

// OpenRiC Workspace - a researcher's assessment of a record as a source (roadmap phase 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'record_id', 'user_id', 'source_kind_id', 'source_form_id', 'completeness_id', 'trust_score', 'rationale', 'bias_context', 'assessed_at'])]
class ResearchSourceAssessment extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_source_assessments';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['assessed_at' => 'datetime', 'trust_score' => 'integer'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceKind(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function sourceForm(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function completeness(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }
}
