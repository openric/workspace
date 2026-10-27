<?php

// OpenRiC Workspace - a background job: what was asked, by whom, and how it ended (roadmap phase 5).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'user_id', 'type_id', 'status_id', 'name', 'subject_table', 'subject_id', 'output', 'error_message', 'stack_trace', 'started_at', 'completed_at', 'duration'])]
class BackgroundJob extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['output' => 'array', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'type_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function hasStatus(string $status): bool
    {
        return $this->status?->notation === $status;
    }

    /** Waiting or under way. */
    public function active(): bool
    {
        return $this->hasStatus('pending') || $this->hasStatus('running');
    }
}
