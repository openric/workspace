<?php

// OpenRiC Workspace - a reviewer's point on a manuscript and the author's reply (roadmap phase 19g, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['submission_id', 'reviewer_label', 'point', 'response', 'revision_note', 'created_by_id'])]
class ResearchPublicationResponse extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_publication_responses';

    public function submission(): BelongsTo
    {
        return $this->belongsTo(ResearchPublicationSubmission::class);
    }
}
