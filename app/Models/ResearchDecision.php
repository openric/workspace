<?php

// OpenRiC Workspace - a decision of a research project's decision log (roadmap phase 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'decision_type_id', 'summary', 'reason', 'related_ref', 'decided_by', 'decided_at', 'created_by_id'])]
class ResearchDecision extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_decisions';

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }

    public function decisionType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
