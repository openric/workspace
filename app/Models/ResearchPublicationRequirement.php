<?php

// OpenRiC Workspace - a requirement of a venue a manuscript is to meet (roadmap phase 19g, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['submission_id', 'label', 'met', 'note', 'sort_order'])]
class ResearchPublicationRequirement extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_publication_requirements';

    protected function casts(): array
    {
        return ['met' => 'boolean'];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(ResearchPublicationSubmission::class);
    }
}
