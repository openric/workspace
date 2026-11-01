<?php

// OpenRiC Workspace - a folder of an account's favourites (Heratio's favorites_folder; roadmap phase 19a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'user_id', 'parent_id', 'name', 'description', 'visibility_id', 'share_token_hash', 'share_expires_at', 'share_access_count', 'share_accessed_at', 'position'])]
class FavouriteFolder extends Model
{
    use Audited, HasUuids;

    protected $table = 'favourite_folders';

    protected function casts(): array
    {
        return ['share_expires_at' => 'immutable_datetime', 'share_accessed_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(FavouriteFolder::class, 'parent_id');
    }

    public function visibility(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'visibility_id');
    }

    public function favourites(): HasMany
    {
        return $this->hasMany(Favourite::class, 'folder_id');
    }
}
