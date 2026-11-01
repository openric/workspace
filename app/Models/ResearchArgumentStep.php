<?php

// OpenRiC Workspace - a step of an argument and the claim it rests on (roadmap phase 19g, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['argument_id', 'slot_id', 'claim_id', 'note', 'sort_order'])]
class ResearchArgumentStep extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_argument_steps';

    public function argument(): BelongsTo
    {
        return $this->belongsTo(ResearchArgument::class);
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(ResearchClaim::class);
    }
}
