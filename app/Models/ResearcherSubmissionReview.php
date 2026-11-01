<?php

// OpenRiC Workspace - a step of the review of a researcher's submission (roadmap phase 19e, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['submission_id', 'reviewer_id', 'action_id', 'comment', 'created_at'])]
class ResearcherSubmissionReview extends Model
{
    use Audited, HasUuids;

    protected $table = 'researcher_submission_reviews';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(ResearcherSubmission::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }
}
