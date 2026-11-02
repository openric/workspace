<?php

// OpenRiC Workspace - an answer of a data management plan (roadmap phase 19h, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['dmp_id', 'section_key', 'label', 'hint', 'body', 'sort_order'])]
class ResearchDmpSection extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_dmp_sections';

    public function dmp(): BelongsTo
    {
        return $this->belongsTo(ResearchDmp::class);
    }
}
