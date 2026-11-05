<?php

// OpenRiC Workspace - a mention set aside until there is more to go on, and whether a new candidate has appeared since
// (Heratio's ahg_mention_park; phase 17b2).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthorityPark extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'mention_id';

    protected $keyType = 'string';

    protected $fillable = ['mention_id', 'parked_by_id', 'parked_at', 'reason', 'new_candidate_available', 'checked_at', 'fingerprint'];

    protected function casts(): array
    {
        return ['parked_at' => 'immutable_datetime', 'checked_at' => 'immutable_datetime', 'new_candidate_available' => 'boolean'];
    }

    public function mention(): BelongsTo
    {
        return $this->belongsTo(AuthorityMention::class, 'mention_id');
    }

    public function parkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parked_by_id');
    }
}
