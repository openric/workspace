<?php

// OpenRiC Workspace - who an embargo opens to, for a time (Heratio's embargo_exception): an account, a role, or an
// IP range, such as a reading room's.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['embargo_id', 'user_id', 'role_id', 'ip_from', 'ip_to', 'valid_from', 'valid_until', 'note', 'granted_by_id'])]
class EmbargoException extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['valid_from' => 'immutable_date', 'valid_until' => 'immutable_date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
