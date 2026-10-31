<?php

// OpenRiC Workspace - a class of records in a retention schedule, with how long they are kept and what then becomes of them, after Heratio's rm_disposal_class (roadmap phase 13b, ADR 0015).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['retention_schedule_id', 'reference', 'title', 'description', 'retention_years', 'retention_months', 'trigger_id', 'action_id', 'review_required', 'citation', 'is_active', 'position'])]
class DisposalClass extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['retention_years' => 'integer', 'retention_months' => 'integer', 'review_required' => 'boolean', 'is_active' => 'boolean'];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(RetentionSchedule::class, 'retention_schedule_id');
    }

    public function trigger(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'trigger_id');
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'action_id');
    }
}
