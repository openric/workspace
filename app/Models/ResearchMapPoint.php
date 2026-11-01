<?php

// OpenRiC Workspace - a point on a research project's map (roadmap phase 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'user_id', 'label', 'description', 'latitude', 'longitude', 'place_name', 'date_valid_from', 'date_valid_to', 'source_table', 'source_id'])]
class ResearchMapPoint extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_map_points';

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float', 'date_valid_from' => 'date', 'date_valid_to' => 'date'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
