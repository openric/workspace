<?php

// OpenRiC Workspace - a research report (roadmap phase 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'user_id', 'project_id', 'title', 'template_type_id', 'description', 'status_id', 'metadata'])]
class ResearchReport extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_reports';

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function templateType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(ResearchReportSection::class, 'report_id')->orderBy('sort_order');
    }

    public function peerReviews(): HasMany
    {
        return $this->hasMany(ResearchPeerReview::class, 'report_id')->orderByDesc('requested_at');
    }
}
