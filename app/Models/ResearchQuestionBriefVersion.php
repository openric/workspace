<?php

// OpenRiC Workspace - a version of a research design brief, never changed once saved (roadmap phase 19g, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['brief_id', 'version_no', 'broad_topic', 'problem_statement', 'research_gap', 'primary_question', 'secondary_questions', 'hypothesis', 'scope_boundaries', 'key_definitions', 'assumptions', 'bias_risks', 'change_reason', 'created_by_id', 'created_at'])]
class ResearchQuestionBriefVersion extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_question_brief_versions';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function brief(): BelongsTo
    {
        return $this->belongsTo(ResearchQuestionBrief::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
