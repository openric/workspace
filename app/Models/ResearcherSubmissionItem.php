<?php

// OpenRiC Workspace - an item of a researcher's submission: a description, a note on a record, a repository or a creator (roadmap phase 19e, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['submission_id', 'parent_item_id', 'item_type_id', 'title', 'identifier', 'level_id', 'scope_and_content', 'extent_and_medium', 'date_display', 'date_start', 'date_end', 'creators', 'subjects', 'places', 'genres', 'access_conditions', 'reproduction_conditions', 'notes', 'repository_name', 'repository_address', 'repository_contact', 'reference_record_id', 'sort_order', 'published_table', 'published_id'])]
class ResearcherSubmissionItem extends Model
{
    use Audited, HasUuids;

    protected $table = 'researcher_submission_items';

    protected function casts(): array
    {
        return ['date_start' => 'date', 'date_end' => 'date'];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(ResearcherSubmission::class);
    }

    public function parentItem(): BelongsTo
    {
        return $this->belongsTo(ResearcherSubmissionItem::class, 'parent_item_id');
    }

    public function itemType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function referenceRecord(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'reference_record_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(ResearcherSubmissionFile::class, 'item_id')->orderBy('sort_order')->orderBy('created_at');
    }
}
