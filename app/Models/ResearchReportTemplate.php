<?php

// OpenRiC Workspace - a template of the sections a research report starts with (roadmap phase 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'template_type_id', 'name', 'description', 'sections_config', 'is_system'])]
class ResearchReportTemplate extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_report_templates';

    protected function casts(): array
    {
        return ['sections_config' => 'array', 'is_system' => 'boolean'];
    }

    public function templateType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }
}
