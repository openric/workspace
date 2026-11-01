<?php

// OpenRiC Workspace - the activity of the catalogue that describes a research project or output (roadmap phase 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'entity_table', 'entity_id', 'activity_id'])]
class ResearchRicMapping extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_ric_map';

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }
}
