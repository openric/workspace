<?php

// OpenRiC Workspace - an article of a research journal (roadmap phase 19g, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['journal_id', 'issue_id', 'title', 'authors', 'abstract', 'keywords', 'body_markdown', 'reference_style', 'doi', 'target_journal_id', 'word_count', 'status_id', 'sort_order'])]
class ResearchJournalArticle extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_journal_articles';

    protected function casts(): array
    {
        return ['word_count' => 'integer'];
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(ResearchJournal::class);
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(ResearchJournalIssue::class);
    }

    public function targetJournal(): BelongsTo
    {
        return $this->belongsTo(ResearchTargetJournal::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }
}
