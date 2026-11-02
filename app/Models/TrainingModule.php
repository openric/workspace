<?php

// OpenRiC Workspace - a module of a training course (roadmap phase 19j, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['course_id', 'title', 'lecture_id', 'body', 'sort_order'])]
class TrainingModule extends Model
{
    use Audited, HasUuids;

    protected $table = 'training_modules';

    public function course(): BelongsTo
    {
        return $this->belongsTo(TrainingCourse::class);
    }

    public function lecture(): BelongsTo
    {
        return $this->belongsTo(ResearchLecture::class);
    }
}
