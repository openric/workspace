<?php

// OpenRiC Workspace - a contradiction the engine found among a project's claims (roadmap phase 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'claim_a_id', 'claim_b_id', 'kind_id', 'signature', 'detail', 'severity_id', 'status_id', 'detected_by_id'])]
class ResearchContradiction extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_contradictions';

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }

    public function claimA(): BelongsTo
    {
        return $this->belongsTo(ResearchClaim::class, 'claim_a_id');
    }

    public function claimB(): BelongsTo
    {
        return $this->belongsTo(ResearchClaim::class, 'claim_b_id');
    }

    public function kind(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function severity(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function detectedBy(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'detected_by_id');
    }
}
