<?php

// OpenRiC Workspace - an incident with an AI system (roadmap phase 17f; Heratio's ai_risk_incident).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'system_id', 'risk_id', 'reporter_id', 'description', 'severity_id', 'inference_id', 'raised_by', 'resolved_at', 'resolution'])]
class AiIncident extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['resolved_at' => 'immutable_datetime'];
    }

    public function system(): BelongsTo
    {
        return $this->belongsTo(AiSystem::class, 'system_id');
    }

    public function risk(): BelongsTo
    {
        return $this->belongsTo(AiRisk::class, 'risk_id');
    }

    public function severity(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'severity_id');
    }
}
