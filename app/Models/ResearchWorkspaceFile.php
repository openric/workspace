<?php

// OpenRiC Workspace - a file kept with a research workspace (roadmap phase 19d, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['workspace_id', 'file_id', 'added_by_id', 'created_at', 'project_id'])]
class ResearchWorkspaceFile extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_workspace_files';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(ResearchWorkspace::class);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(ResearchFile::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_id');
    }

    /** The research project it belongs to (phase 19f). */
    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }
}
