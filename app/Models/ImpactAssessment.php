<?php

// OpenRiC Workspace - a personal information impact assessment (Heratio's ahg_dpia); roadmap phase 18a, ADR 0016.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'processing_activity_id', 'name', 'description', 'necessity', 'risks', 'measures', 'residual_risks', 'officer_opinion', 'officer_consulted_on', 'status_id', 'signed_off_by_id', 'signed_off_at', 'created_by_id'])]
class ImpactAssessment extends Model
{
    use Audited, HasUuids;

    protected $table = 'impact_assessments';

    protected function casts(): array
    {
        return ['officer_consulted_on' => 'immutable_date', 'signed_off_at' => 'immutable_datetime'];
    }

    public function processingActivity(): BelongsTo
    {
        return $this->belongsTo(ProcessingActivity::class, 'processing_activity_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function signedOffBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_off_by_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
