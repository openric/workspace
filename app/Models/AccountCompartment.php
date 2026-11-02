<?php

// OpenRiC Workspace - an account's access to a compartment (roadmap phase 18c, ADR 0016).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'compartment_id', 'granted_by_id', 'granted_at', 'briefed_at', 'expires_at'])]
class AccountCompartment extends Model
{
    use Audited, HasUuids;

    protected $table = 'account_compartments';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['granted_at' => 'datetime', 'briefed_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function compartment(): BelongsTo
    {
        return $this->belongsTo(SecurityCompartment::class, 'compartment_id');
    }
}
