<?php

// OpenRiC Workspace - a result brought from analysis outside the Workspace (roadmap phase 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_id', 'result_type_id', 'title', 'source_data_ref', 'source_data_version', 'method', 'code_ref', 'generated_at', 'researcher_decision', 'artifact_file_id', 'created_by_id'])]
class ResearchAnalysisResult extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_analysis_results';

    protected function casts(): array
    {
        return ['generated_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }

    public function resultType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function artifact(): BelongsTo
    {
        return $this->belongsTo(ResearchFile::class, 'artifact_file_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function claimLinks(): HasMany
    {
        return $this->hasMany(ResearchAnalysisResultClaim::class, 'result_id');
    }
}
