<?php

// OpenRiC Workspace - one file of a digital instantiation: the master as received, or a copy made from it (WS-007).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'instantiation_id', 'use_id', 'original_name', 'mime_type', 'size_bytes', 'sha256', 'storage_path', 'width', 'height',
    'scanned_with', 'scanned_at', 'fixity_checked_at', 'fixity_failed_at'])]
class DigitalFile extends Model
{
    use Audited, HasUuids;

    /** A passing fixity check is not audited, so the daily check does not fill the log; a failure is. */
    public function notAudited(): array
    {
        return ['fixity_checked_at'];
    }

    protected function casts(): array
    {
        return ['scanned_at' => 'immutable_datetime', 'fixity_checked_at' => 'immutable_datetime', 'fixity_failed_at' => 'immutable_datetime', 'size_bytes' => 'integer'];
    }

    public function instantiation(): BelongsTo
    {
        return $this->belongsTo(Instantiation::class);
    }

    public function use(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'use_id');
    }
}
