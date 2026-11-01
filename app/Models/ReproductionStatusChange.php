<?php

// OpenRiC Workspace - a change of a reproduction request's status (Heratio's research_request_status_history; roadmap phase 19c).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['reproduction_request_id', 'old_status', 'new_status', 'changed_by_id', 'notes', 'created_at'])]
class ReproductionStatusChange extends Model
{
    use HasUuids;

    protected $table = 'reproduction_status_changes';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_id');
    }
}
