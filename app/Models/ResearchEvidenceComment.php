<?php

// OpenRiC Workspace - a comment on the evidence of a research project: a collection or an item of it (roadmap phase 19f, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'collection_id', 'item_id', 'parent_id', 'author_id', 'body', 'is_resolved', 'resolved_by_id', 'resolved_at'])]
class ResearchEvidenceComment extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_evidence_comments';

    protected function casts(): array
    {
        return ['is_resolved' => 'boolean', 'resolved_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(ResearchCollection::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ResearchCollectionItem::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }
}
