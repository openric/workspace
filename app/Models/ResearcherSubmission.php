<?php

// OpenRiC Workspace - a researcher's submission of descriptions for the catalogue (roadmap phase 19e, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'user_id', 'researcher_id', 'title', 'description', 'repository_id', 'parent_record_id', 'source_type_id', 'source_file_id', 'include_images', 'status_id', 'total_items', 'total_files', 'total_file_size', 'return_comment', 'reject_comment', 'reviewer_id', 'import_id', 'submitted_at', 'published_at', 'project_id'])]
class ResearcherSubmission extends Model
{
    use Audited, HasUuids;

    protected $table = 'researcher_submissions';

    protected function casts(): array
    {
        return ['include_images' => 'boolean', 'submitted_at' => 'datetime', 'published_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function researcher(): BelongsTo
    {
        return $this->belongsTo(Researcher::class);
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function parentRecord(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'parent_record_id');
    }

    public function sourceType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function sourceFile(): BelongsTo
    {
        return $this->belongsTo(ResearchFile::class, 'source_file_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ResearcherSubmissionItem::class, 'submission_id')->orderBy('sort_order')->orderBy('created_at');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ResearcherSubmissionReview::class, 'submission_id')->orderBy('created_at');
    }

    /** The research project it belongs to (phase 19f). */
    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }
}
