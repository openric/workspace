<?php

// OpenRiC Workspace - a snapshot of a research project's records, to cite as they were (roadmap phase 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_id', 'user_id', 'title', 'description', 'hash_sha256', 'query_state', 'rights_state', 'metadata', 'item_count', 'status_id', 'frozen_at', 'citation_id'])]
class ResearchSnapshot extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_snapshots';

    protected function casts(): array
    {
        return ['query_state' => 'array', 'rights_state' => 'array', 'metadata' => 'array', 'frozen_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ResearchSnapshotItem::class, 'snapshot_id')->orderBy('sort_order');
    }
}
