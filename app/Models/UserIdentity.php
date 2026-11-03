<?php

// OpenRiC Workspace - an account's identity at a sign-in provider: the provider's subject, linked once (roadmap phase 12b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'provider_id', 'subject', 'email', 'last_signed_in_at'])]
class UserIdentity extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['last_signed_in_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(SignInProvider::class, 'provider_id');
    }
}
