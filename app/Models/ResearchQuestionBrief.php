<?php

// OpenRiC Workspace - a research project's research design brief (roadmap phase 19g, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_id', 'current_version', 'status_id', 'created_by_id'])]
class ResearchQuestionBrief extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_question_briefs';

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ResearchQuestionBriefVersion::class, 'brief_id')->orderByDesc('version_no');
    }
}
