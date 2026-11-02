<?php

// OpenRiC Workspace - a grant or award funding a research project (roadmap phase 19h, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'title', 'funder_name', 'funder_type_id', 'award_reference', 'amount', 'currency_id', 'status_id', 'start_date', 'end_date', 'notes', 'dmp_id', 'created_by_id'])]
class ResearchFunding extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_funding';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'start_date' => 'date', 'end_date' => 'date'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }

    public function funderType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function dmp(): BelongsTo
    {
        return $this->belongsTo(ResearchDmp::class);
    }
}
