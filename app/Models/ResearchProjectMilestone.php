<?php

// OpenRiC Workspace - a milestone or deliverable of a research project (roadmap phase 19f, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'title', 'milestone_type_id', 'description', 'deliverable', 'due_date', 'completed_date', 'completed_by_id', 'status_id', 'progress_pct', 'sort_order', 'created_by_id'])]
class ResearchProjectMilestone extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_project_milestones';

    protected function casts(): array
    {
        return ['due_date' => 'date', 'completed_date' => 'date'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }

    public function milestoneType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_id');
    }
}
