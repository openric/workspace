<?php

// OpenRiC Workspace - one fixity run and what it found (Heratio's integrity_run; roadmap phase 12a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'schedule_id', 'status_id', 'trigger_id', 'user_id', 'files_checked', 'files_passed', 'files_changed', 'files_missing', 'bytes_checked',
    'error_message', 'started_at', 'completed_at'])]
class FixityRun extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['files_checked' => 'integer', 'files_passed' => 'integer', 'files_changed' => 'integer', 'files_missing' => 'integer', 'bytes_checked' => 'integer',
            'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(FixitySchedule::class, 'schedule_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function trigger(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'trigger_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(FixityCheck::class, 'run_id');
    }
}
