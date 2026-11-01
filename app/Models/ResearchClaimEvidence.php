<?php

// OpenRiC Workspace - a piece of evidence for or against a claim (roadmap phase 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['claim_id', 'source_type_id', 'source_id', 'selector', 'relationship_id', 'note', 'added_by_id', 'created_at'])]
class ResearchClaimEvidence extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_claim_evidence';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['selector' => 'array', 'created_at' => 'datetime'];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(ResearchClaim::class);
    }

    public function sourceType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function relationship(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_id');
    }
}
