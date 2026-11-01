<?php

// OpenRiC Workspace - a journal of the directory a researcher may aim a manuscript at (roadmap phase 19g, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'title', 'subtitle', 'publisher', 'homepage_url', 'submission_url', 'languages', 'subject_scope', 'article_types', 'accreditation', 'reference_style', 'structure_notes', 'peer_review', 'apc_amount', 'turnaround', 'notes', 'issn', 'eissn', 'accreditation_market', 'max_words', 'abstract_max_words', 'open_access', 'status_id'])]
class ResearchTargetJournal extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_target_journals';

    protected function casts(): array
    {
        return ['open_access' => 'boolean', 'max_words' => 'integer', 'abstract_max_words' => 'integer'];
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }
}
