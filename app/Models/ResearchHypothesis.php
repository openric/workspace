<?php

// OpenRiC Workspace - a hypothesis of a research project (roadmap phase 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_id', 'user_id', 'statement', 'status_id', 'evidence_count', 'tags'])]
class ResearchHypothesis extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_hypotheses';

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(ResearchHypothesisEvidence::class, 'hypothesis_id')->orderBy('created_at');
    }
}
