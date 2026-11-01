<?php

// OpenRiC Workspace - a member of a research project's team (roadmap phase 19f, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'person_name', 'user_id', 'role_id', 'affiliation', 'email', 'orcid', 'is_lead', 'contribution_note', 'start_date', 'end_date', 'status_id', 'created_by_id'])]
class ResearchProjectTeamMember extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_project_team_members';

    protected function casts(): array
    {
        return ['is_lead' => 'boolean', 'start_date' => 'date', 'end_date' => 'date'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }
}
