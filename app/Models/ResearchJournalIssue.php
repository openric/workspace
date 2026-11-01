<?php

// OpenRiC Workspace - an issue of a research journal (roadmap phase 19g, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['journal_id', 'volume', 'number', 'title', 'issue_date', 'description', 'status_id', 'sort_order'])]
class ResearchJournalIssue extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_journal_issues';

    protected function casts(): array
    {
        return ['issue_date' => 'date'];
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(ResearchJournal::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function articles(): HasMany
    {
        return $this->hasMany(ResearchJournalArticle::class, 'issue_id')->orderBy('sort_order')->orderBy('title');
    }
}
