<?php

// OpenRiC Workspace - a search across several fonds, logged (roadmap phase 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'user_id', 'query_text', 'fonds_ids', 'results_count', 'elapsed_ms', 'created_at'])]
class ResearchCrossFondsQuery extends Model
{
    use HasUuids;

    protected $table = 'research_cross_fonds_queries';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['fonds_ids' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
