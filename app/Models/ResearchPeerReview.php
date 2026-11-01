<?php

// OpenRiC Workspace - a peer review of a research report (roadmap phase 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['report_id', 'requested_by_id', 'reviewer_id', 'status_id', 'feedback', 'rating', 'requested_at', 'completed_at'])]
class ResearchPeerReview extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_peer_reviews';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'completed_at' => 'datetime', 'rating' => 'integer'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(ResearchReport::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }
}
