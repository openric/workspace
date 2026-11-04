<?php

// OpenRiC Workspace - a unit sent to Archivematica as a transfer and followed to its AIP, or a package fetched
// from it (Heratio's am_job; roadmap phase 15d).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'record_resource_id', 'direction_id', 'status_id', 'name', 'transfer_uuid', 'sip_uuid', 'aip_uuid', 'microservice', 'last_polled_at', 'error', 'payload', 'user_id'])]
class ArchivematicaJob extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['payload' => 'array', 'last_polled_at' => 'immutable_datetime'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function direction(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'direction_id');
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
