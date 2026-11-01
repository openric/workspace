<?php

// OpenRiC Workspace - a research journal, or a manuscript being prepared for one (roadmap phase 19g, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'user_id', 'kind_id', 'title', 'subtitle', 'publisher', 'description', 'aims_scope', 'editor_name', 'editor_email', 'doi', 'issn', 'eissn', 'target_journal_id', 'cover_record_id', 'status_id'])]
class ResearchJournal extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_journals';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function kind(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function targetJournal(): BelongsTo
    {
        return $this->belongsTo(ResearchTargetJournal::class);
    }

    public function coverRecord(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'cover_record_id');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(ResearchJournalIssue::class, 'journal_id')->orderBy('sort_order')->orderBy('issue_date');
    }

    public function articles(): HasMany
    {
        return $this->hasMany(ResearchJournalArticle::class, 'journal_id')->orderBy('sort_order')->orderBy('title');
    }
}
