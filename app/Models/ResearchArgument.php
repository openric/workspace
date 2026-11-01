<?php

// OpenRiC Workspace - a research project's argument (roadmap phase 19g, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_id', 'title', 'central_thesis', 'created_by_id'])]
class ResearchArgument extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_arguments';

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ResearchArgumentStep::class, 'argument_id')->orderBy('sort_order');
    }
}
