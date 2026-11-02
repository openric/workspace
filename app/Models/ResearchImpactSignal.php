<?php

// OpenRiC Workspace - a sign that a published output of a research project has been cited, mentioned or reused (roadmap phase 19h, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'output_id', 'doi', 'signal_type_id', 'title', 'detail', 'url', 'source', 'detected_at'])]
class ResearchImpactSignal extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_impact_signals';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['detected_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }

    public function output(): BelongsTo
    {
        return $this->belongsTo(ResearchProjectOutput::class);
    }

    public function signalType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }
}
