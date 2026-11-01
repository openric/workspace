<?php

// OpenRiC Workspace - a research workspace that researchers share (roadmap phase 19d, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'owner_id', 'name', 'description', 'visibility_id', 'settings'])]
class ResearchWorkspace extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_workspaces';

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function visibility(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(ResearchWorkspaceMember::class, 'workspace_id');
    }

    public function resources(): HasMany
    {
        return $this->hasMany(ResearchWorkspaceResource::class, 'workspace_id')->orderBy('sort_order')->orderBy('added_at');
    }

    public function files(): HasMany
    {
        return $this->hasMany(ResearchWorkspaceFile::class, 'workspace_id')->orderByDesc('created_at');
    }
}
