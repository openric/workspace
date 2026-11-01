<?php

// OpenRiC Workspace - a template of a research method, with guidance for each of its areas (roadmap phase 19g, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['institution_id', 'code', 'name', 'discipline', 'description', 'guidance', 'is_active', 'sort_order'])]
class ResearchMethodTemplate extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_method_templates';

    protected function casts(): array
    {
        return ['guidance' => 'array', 'is_active' => 'boolean'];
    }
}
