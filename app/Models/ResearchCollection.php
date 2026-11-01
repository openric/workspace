<?php

// OpenRiC Workspace - a researcher's collection of records and other things (roadmap phase 19d, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'user_id', 'name', 'description', 'is_public', 'share_token_hash', 'project_id'])]
class ResearchCollection extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_collections';

    protected function casts(): array
    {
        return ['is_public' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ResearchCollectionItem::class, 'collection_id')->orderBy('sort_order')->orderBy('created_at');
    }

    /** The research project it belongs to (phase 19f). */
    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }
}
