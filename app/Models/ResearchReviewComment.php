<?php

// OpenRiC Workspace - a reviewer's comment on a research project, or on one of its claims (roadmap phase 19g, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_id', 'claim_id', 'thread_id', 'author_id', 'body', 'resolved'])]
class ResearchReviewComment extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_review_comments';

    protected function casts(): array
    {
        return ['resolved' => 'boolean'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(ResearchClaim::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(ResearchReviewComment::class, 'thread_id')->orderBy('created_at');
    }
}
