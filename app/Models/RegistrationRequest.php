<?php

// OpenRiC Workspace - a visitor's request for an account (Heratio's user_registration_request).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'name', 'email', 'password', 'reason', 'approved_at', 'rejected_at', 'decided_by_id', 'account_id'])]
#[Hidden(['password'])]
class RegistrationRequest extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['password' => 'hashed', 'approved_at' => 'immutable_datetime', 'rejected_at' => 'immutable_datetime'];
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }

    public function isPending(): bool
    {
        return $this->approved_at === null && $this->rejected_at === null;
    }
}
