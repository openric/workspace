<?php

// OpenRiC Workspace - what files to check for fixity, and how often (Heratio's integrity_schedule; roadmap phase 12a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'name', 'scope_id', 'repository_id', 'record_resource_id', 'frequency_id', 'stale_days', 'batch_size', 'max_runtime_minutes',
    'is_enabled', 'notify_user_id', 'last_run_at', 'next_run_at', 'created_by_id'])]
class FixitySchedule extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['stale_days' => 'integer', 'batch_size' => 'integer', 'max_runtime_minutes' => 'integer', 'is_enabled' => 'boolean',
            'last_run_at' => 'immutable_datetime', 'next_run_at' => 'immutable_datetime'];
    }

    public function scope(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'scope_id');
    }

    public function frequency(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'frequency_id');
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function notifyUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'notify_user_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(FixityRun::class, 'schedule_id')->orderByDesc('started_at');
    }
}
