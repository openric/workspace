<?php

// OpenRiC Workspace - one copy of the object store to a replication target, or one comparison with it, and what
// it found (Heratio's preservation_replication_log; roadmap phase 15c).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['target_id', 'operation_id', 'status_id', 'files_total', 'files_synced', 'files_failed', 'bytes_transferred', 'started_at', 'completed_at', 'duration_ms', 'error_message', 'details', 'user_id'])]
class ReplicationRun extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['files_total' => 'integer', 'files_synced' => 'integer', 'files_failed' => 'integer', 'bytes_transferred' => 'integer', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'duration_ms' => 'integer', 'details' => 'array'];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(ReplicationTarget::class, 'target_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'operation_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
