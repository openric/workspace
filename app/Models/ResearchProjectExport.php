<?php

// OpenRiC Workspace - an export of a research project, logged (roadmap phase 19f, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'format_id', 'exported_by_id', 'exported_at'])]
class ResearchProjectExport extends Model
{
    use HasUuids;

    protected $table = 'research_project_exports';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['exported_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }

    public function format(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function exportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exported_by_id');
    }
}
