<?php

// OpenRiC Workspace - a learner's enrolment in a training course (roadmap phase 19j, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['course_id', 'user_id', 'learner_name', 'learner_email', 'status_id', 'score', 'enrolled_at', 'completed_at'])]
class TrainingEnrolment extends Model
{
    use Audited, HasUuids;

    protected $table = 'training_enrolments';

    protected function casts(): array
    {
        return ['enrolled_at' => 'datetime', 'completed_at' => 'datetime', 'score' => 'integer'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(TrainingCourse::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function progress(): HasMany
    {
        return $this->hasMany(TrainingProgress::class, 'enrolment_id');
    }

    public function certificate(): HasOne
    {
        return $this->hasOne(TrainingCertificate::class, 'enrolment_id');
    }
}
