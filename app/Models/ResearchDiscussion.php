<?php

// OpenRiC Workspace - a discussion of a research project or research workspace, or a reply (roadmap phase 19f, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'workspace_id', 'project_id', 'parent_id', 'user_id', 'subject', 'content', 'is_pinned', 'is_resolved', 'resolved_by_id', 'resolved_at'])]
class ResearchDiscussion extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_discussions';

    protected function casts(): array
    {
        return ['is_pinned' => 'boolean', 'is_resolved' => 'boolean', 'resolved_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(ResearchWorkspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ResearchDiscussion::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(ResearchDiscussion::class, 'parent_id')->orderBy('created_at');
    }
}
