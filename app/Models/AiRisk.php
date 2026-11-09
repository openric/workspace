<?php

// OpenRiC Workspace - a risk of an AI system (roadmap phase 17f; Heratio's ai_risk_register).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'system_id', 'description', 'severity_id', 'likelihood_id', 'use_id', 'affected_group', 'mitigation', 'residual_id', 'status_id', 'last_reviewed_at', 'reviewer_id'])]
class AiRisk extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['last_reviewed_at' => 'immutable_datetime'];
    }

    public function system(): BelongsTo
    {
        return $this->belongsTo(AiSystem::class, 'system_id');
    }

    public function severity(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'severity_id');
    }

    public function likelihood(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'likelihood_id');
    }

    public function use(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'use_id');
    }

    public function residual(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'residual_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(AiIncident::class, 'risk_id');
    }
}
