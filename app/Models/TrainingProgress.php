<?php

// OpenRiC Workspace - a module a learner has done (roadmap phase 19j, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['enrolment_id', 'module_id', 'completed_at'])]
class TrainingProgress extends Model
{
    use Audited, HasUuids;

    protected $table = 'training_progress';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(TrainingEnrolment::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(TrainingModule::class);
    }
}
