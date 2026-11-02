<?php

// OpenRiC Workspace - a section of a grant application (roadmap phase 19h, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['draft_id', 'section_key', 'label', 'hint', 'body', 'sort_order'])]
class ResearchGrantSection extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_grant_sections';

    public function draft(): BelongsTo
    {
        return $this->belongsTo(ResearchGrantDraft::class);
    }
}
