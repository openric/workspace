<?php

// OpenRiC Workspace - a message in the dialogue on a repatriation claim, shared on the joint record or kept internal
// (Heratio's repatriation_claim_message; phase 17d2).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RepatriationMessage extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['claim_id', 'author_role_id', 'author_name', 'author_user_id', 'share_id', 'body', 'visibility_id'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'author_role_id');
    }

    public function visibility(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'visibility_id');
    }
}
