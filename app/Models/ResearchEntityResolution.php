<?php

// OpenRiC Workspace - a proposal that two entities of the catalogue are one (roadmap phase 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'project_id', 'entity_a_table', 'entity_a_id', 'entity_b_table', 'entity_b_id', 'confidence', 'match_method', 'status_id', 'proposer_id', 'resolver_id', 'resolved_at', 'notes', 'evidence'])]
class ResearchEntityResolution extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_entity_resolutions';

    protected function casts(): array
    {
        return ['confidence' => 'float', 'resolved_at' => 'datetime', 'evidence' => 'array'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposer_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolver_id');
    }
}
