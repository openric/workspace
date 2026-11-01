<?php

// OpenRiC Workspace - an entity, or a link, an account keeps for later (Heratio's favorites; roadmap phase 19a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'user_id', 'folder_id', 'subject_table', 'subject_id', 'url', 'title', 'notes', 'last_viewed_at'])]
class Favourite extends Model
{
    use Audited, HasUuids;

    protected $table = 'favourites';

    protected function casts(): array
    {
        return ['last_viewed_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(FavouriteFolder::class, 'folder_id');
    }
}
