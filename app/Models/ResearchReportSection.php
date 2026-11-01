<?php

// OpenRiC Workspace - a section of a research report (roadmap phase 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['report_id', 'section_type_id', 'title', 'content', 'bibliography_id', 'collection_id', 'settings', 'sort_order'])]
class ResearchReportSection extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_report_sections';

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(ResearchReport::class);
    }

    public function sectionType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function bibliography(): BelongsTo
    {
        return $this->belongsTo(Bibliography::class);
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(ResearchCollection::class);
    }
}
