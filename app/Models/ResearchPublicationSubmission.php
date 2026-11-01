<?php

// OpenRiC Workspace - a manuscript of a research project sent to a venue (roadmap phase 19g, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_id', 'target_journal_id', 'venue_name', 'status_id', 'manuscript_title', 'submitted_at', 'decision_at', 'doi', 'repository_url', 'notes', 'created_by_id'])]
class ResearchPublicationSubmission extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_publication_submissions';

    protected function casts(): array
    {
        return ['submitted_at' => 'date', 'decision_at' => 'date'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }

    public function targetJournal(): BelongsTo
    {
        return $this->belongsTo(ResearchTargetJournal::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(ResearchPublicationRequirement::class, 'submission_id')->orderBy('sort_order');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(ResearchPublicationResponse::class, 'submission_id')->orderBy('created_at');
    }
}
