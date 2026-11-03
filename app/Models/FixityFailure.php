<?php

// OpenRiC Workspace - a file that failed its fixity check, kept open until someone resolves it or it passes again
// (Heratio's integrity_dead_letter; roadmap phase 12a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'digital_file_id', 'outcome_id', 'status_id', 'consecutive_failures', 'first_failed_at', 'last_failed_at', 'last_run_id',
    'acknowledged_by_id', 'acknowledged_at', 'resolution_notes', 'resolved_at'])]
class FixityFailure extends Model
{
    use Audited, HasUuids;

    /** The statuses under which a failure is still open. */
    public const OPEN = ['open', 'acknowledged', 'investigating'];

    protected function casts(): array
    {
        return ['consecutive_failures' => 'integer', 'first_failed_at' => 'immutable_datetime', 'last_failed_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime'];
    }

    public function outcome(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'outcome_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(DigitalFile::class, 'digital_file_id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_id');
    }
}
