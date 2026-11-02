<?php

// OpenRiC Workspace - a training course (roadmap phase 19j, ADR 0017).
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

#[Fillable(['institution_id', 'user_id', 'title', 'description', 'audience', 'language', 'pass_mark', 'status_id', 'sort_order'])]
class TrainingCourse extends Model
{
    use Audited, HasUuids;

    protected $table = 'training_courses';

    protected function casts(): array
    {
        return ['pass_mark' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function modules(): HasMany
    {
        return $this->hasMany(TrainingModule::class, 'course_id')->orderBy('sort_order');
    }

    public function enrolments(): HasMany
    {
        return $this->hasMany(TrainingEnrolment::class, 'course_id')->orderByDesc('enrolled_at');
    }

    public function assessment(): HasOne
    {
        return $this->hasOne(TrainingAssessment::class, 'course_id');
    }
}
