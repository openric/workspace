<?php

// OpenRiC Workspace - a section a grant or data management plan template starts with (roadmap phase 19h, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['template_id', 'section_key', 'label', 'hint', 'sort_order'])]
class ResearchTemplateSection extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_template_sections';

    public $timestamps = false;

    public function template(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }
}
