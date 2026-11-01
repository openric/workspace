<?php

// OpenRiC Workspace - a claim an analysis result bears on (roadmap phase 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['result_id', 'claim_id', 'relationship_id', 'note', 'created_at'])]
class ResearchAnalysisResultClaim extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_analysis_result_claims';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(ResearchAnalysisResult::class);
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(ResearchClaim::class);
    }

    public function relationship(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }
}
