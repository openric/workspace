<?php

// OpenRiC Workspace - a change of a repatriation claim's status, with who made it and why (Heratio's
// repatriation_claim_status_log; phase 17d2).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RepatriationStatusChange extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['claim_id', 'from_status_id', 'to_status_id', 'note', 'changed_by_id', 'changed_by_name'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'from_status_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'to_status_id');
    }
}
