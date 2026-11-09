<?php

// OpenRiC Workspace - one AI system in the institution's register, with its oversight (roadmap phase 17f; Heratio's ai_system and ai_oversight_policy).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'service_id', 'name', 'description', 'purpose', 'provider', 'role_id', 'risk_class_id', 'lifecycle_id', 'deployment_context', 'human_oversight', 'owner', 'last_review_date', 'next_review_date', 'requires_human_review', 'dual_review_required', 'automation_bias_text', 'halted_at', 'halted_reason', 'halted_by_id'])]
class AiSystem extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['requires_human_review' => 'boolean', 'dual_review_required' => 'boolean', 'halted_at' => 'immutable_datetime', 'last_review_date' => 'immutable_date', 'next_review_date' => 'immutable_date'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'service_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'role_id');
    }

    public function riskClass(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'risk_class_id');
    }

    public function lifecycle(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'lifecycle_id');
    }

    public function risks(): HasMany
    {
        return $this->hasMany(AiRisk::class, 'system_id');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(AiIncident::class, 'system_id');
    }
}
