<?php

// OpenRiC Workspace - a grant application being written for a research project (roadmap phase 19h, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_id', 'template_id', 'title', 'status_id', 'created_by_id'])]
class ResearchGrantDraft extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_grant_drafts';

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(ResearchGrantSection::class, 'draft_id')->orderBy('sort_order');
    }
}
