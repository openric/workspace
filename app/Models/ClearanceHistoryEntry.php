<?php

// OpenRiC Workspace - a change to an account's clearance (roadmap phase 18c, ADR 0016).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'previous_level_id', 'new_level_id', 'action_id', 'changed_by_id', 'reason', 'created_at'])]
class ClearanceHistoryEntry extends Model
{
    use Audited, HasUuids;

    protected $table = 'clearance_history';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function previousLevel(): BelongsTo
    {
        return $this->belongsTo(SecurityLevel::class, 'previous_level_id');
    }

    public function newLevel(): BelongsTo
    {
        return $this->belongsTo(SecurityLevel::class, 'new_level_id');
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
