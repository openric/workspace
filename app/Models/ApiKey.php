<?php

// OpenRiC Workspace - an API key of an account (ADR 0007; Heratio's ahg_api_key).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['institution_id', 'user_id', 'name', 'key_hash', 'prefix', 'hourly_limit', 'daily_limit', 'expires_at', 'revoked_at', 'revoked_by_id', 'created_by_id'])]
#[Hidden(['key_hash'])]
class ApiKey extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'last_used_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    /** Using a key is not a change to it. */
    public function notAudited(): array
    {
        return ['last_used_at'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_id');
    }

    public function scopes(): BelongsToMany
    {
        return $this->belongsToMany(Concept::class, 'api_key_scopes', 'api_key_id', 'scope_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** May the key be used now: not revoked, not expired, and its account not disabled? */
    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired() && ! $this->user->isDisabled();
    }
}
