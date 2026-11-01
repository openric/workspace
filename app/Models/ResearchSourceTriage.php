<?php

// OpenRiC Workspace - how a research project sorts one of its sources, and how far it is read (roadmap phase 19g, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'source_type_id', 'source_id', 'triage_category_id', 'read_status_id', 'notes', 'updated_by_id'])]
class ResearchSourceTriage extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_source_triage';

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }

    public function sourceType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function triageCategory(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function readStatus(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }
}
