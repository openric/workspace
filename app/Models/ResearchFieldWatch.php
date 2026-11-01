<?php

// OpenRiC Workspace - a cited work a research project watches by its DOI (roadmap phase 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_id', 'doi', 'title', 'source_ref', 'added_by_id', 'last_checked_at', 'created_at'])]
class ResearchFieldWatch extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_field_watches';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['last_checked_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_id');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(ResearchFieldAlert::class, 'watch_id')->orderByDesc('detected_at');
    }
}
