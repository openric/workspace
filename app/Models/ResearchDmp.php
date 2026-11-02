<?php

// OpenRiC Workspace - a data management plan of a research project (roadmap phase 19h, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_id', 'title', 'status_id', 'funder', 'template_id', 'language', 'contact_name', 'contact_email', 'created_by_id'])]
class ResearchDmp extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_dmps';

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(ResearchDmpSection::class, 'dmp_id')->orderBy('sort_order');
    }
}
