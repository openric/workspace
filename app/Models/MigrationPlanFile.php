<?php

// OpenRiC Workspace - one file of a migration plan and where its migration stands (Heratio's
// preservation_migration_plan_object; roadmap phase 15c).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['plan_id', 'digital_file_id', 'status_id', 'conversion_id', 'queued_at', 'started_at', 'completed_at', 'error_message', 'retry_count'])]
class MigrationPlanFile extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['queued_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'retry_count' => 'integer'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MigrationPlan::class, 'plan_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(DigitalFile::class, 'digital_file_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function conversion(): BelongsTo
    {
        return $this->belongsTo(FormatConversion::class, 'conversion_id');
    }
}
