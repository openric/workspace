<?php

// OpenRiC Workspace - a way to carry files of one format into another, by a tool, with what it loses (Heratio's
// preservation_migration_pathway; roadmap phase 15c).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['source_puid', 'target_puid', 'tool_id', 'target_ext', 'target_mime', 'options', 'quality_impact_id', 'fidelity_score', 'is_recommended', 'is_automated', 'priority', 'notes'])]
class MigrationPathway extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['options' => 'array', 'fidelity_score' => 'integer', 'is_recommended' => 'boolean', 'is_automated' => 'boolean', 'priority' => 'integer'];
    }

    public function tool(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'tool_id');
    }

    public function qualityImpact(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'quality_impact_id');
    }
}
