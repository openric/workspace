<?php

// OpenRiC Workspace - a researcher's notebook (roadmap phase 19d, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'user_id', 'title', 'summary', 'cover_record_id', 'sort_order', 'promoted_to_project_id', 'promoted_at'])]
class ResearchNotebook extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_notebooks';

    protected function casts(): array
    {
        return ['promoted_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function coverRecord(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'cover_record_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ResearchNotebookItem::class, 'notebook_id')->orderByDesc('pinned')->orderBy('sort_order')->orderBy('created_at');
    }

    /** The research project it was promoted to (phase 19f). */
    public function promotedTo(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class, 'promoted_to_project_id');
    }
}
