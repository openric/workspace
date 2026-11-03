<?php

// OpenRiC Workspace - one fixity check of one file, a PREMIS fixity check event (Heratio's integrity_ledger; roadmap phase 12a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'run_id', 'digital_file_id', 'outcome_id', 'expected_sha256', 'computed_sha256', 'expected_size', 'found_size', 'duration_ms', 'user_id', 'checked_at'])]
class FixityCheck extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['expected_size' => 'integer', 'found_size' => 'integer', 'duration_ms' => 'integer', 'checked_at' => 'immutable_datetime'];
    }

    public function outcome(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'outcome_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(DigitalFile::class, 'digital_file_id');
    }
}
