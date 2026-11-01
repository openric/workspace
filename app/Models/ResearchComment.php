<?php

// OpenRiC Workspace - a comment on an annotation, a diary entry or a collection (roadmap phase 19f, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'subject_table', 'subject_id', 'parent_id', 'user_id', 'content', 'is_resolved', 'resolved_by_id', 'resolved_at'])]
class ResearchComment extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_comments';

    protected function casts(): array
    {
        return ['is_resolved' => 'boolean', 'resolved_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }
}
