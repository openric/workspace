<?php

// OpenRiC Workspace - a place the object store is copied to, a folder here or on another server reached by
// rsync over SSH, on a schedule (Heratio's preservation_replication_target; roadmap phase 15c).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'type_id', 'path', 'host', 'username', 'port', 'ssh_key_path', 'is_active', 'frequency_id', 'last_sync_at', 'next_sync_at', 'last_status_id', 'last_sync_files', 'last_sync_bytes'])]
class ReplicationTarget extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['port' => 'integer', 'is_active' => 'boolean', 'last_sync_at' => 'immutable_datetime', 'next_sync_at' => 'immutable_datetime', 'last_sync_files' => 'integer', 'last_sync_bytes' => 'integer'];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'type_id');
    }

    public function frequency(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'frequency_id');
    }

    public function lastStatus(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'last_status_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ReplicationRun::class, 'target_id');
    }
}
