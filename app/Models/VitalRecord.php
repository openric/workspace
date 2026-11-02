<?php

// OpenRiC Workspace - a record flagged as vital to the institution, with its review cycle (roadmap phase 13c, ADR 0015).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['record_resource_id', 'reason', 'review_cycle_days', 'next_review_on', 'last_reviewed_at', 'last_reviewed_by_id', 'is_active', 'created_by_id'])]
class VitalRecord extends Model
{
    use Audited, HasUuids;

    protected $table = 'vital_records';

    protected function casts(): array
    {
        return ['review_cycle_days' => 'integer', 'next_review_on' => 'date', 'last_reviewed_at' => 'datetime', 'is_active' => 'boolean'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function lastReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_reviewed_by_id');
    }
}
