<?php

// OpenRiC Workspace - a resource of a research workspace (roadmap phase 19d, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['workspace_id', 'resource_type_id', 'resource_id', 'external_url', 'title', 'description', 'added_by_id', 'sort_order', 'added_at'])]
class ResearchWorkspaceResource extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_workspace_resources';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['added_at' => 'datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(ResearchWorkspace::class);
    }

    public function resourceType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_id');
    }
}
