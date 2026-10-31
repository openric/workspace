<?php

// OpenRiC Workspace - a retention schedule, issued under a disposal authority, after Heratio's rm_retention_schedule (roadmap phase 13b, ADR 0015).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'reference', 'title', 'description', 'authority', 'jurisdiction', 'effective_on', 'review_on', 'expires_on', 'status_id', 'version', 'previous_id', 'approved_by', 'approved_at', 'created_by_id'])]
class RetentionSchedule extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['effective_on' => 'immutable_date', 'review_on' => 'immutable_date', 'expires_on' => 'immutable_date', 'approved_at' => 'immutable_datetime', 'version' => 'integer'];
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function previous(): BelongsTo
    {
        return $this->belongsTo(RetentionSchedule::class, 'previous_id');
    }

    public function classes(): HasMany
    {
        return $this->hasMany(DisposalClass::class, 'retention_schedule_id');
    }
}
