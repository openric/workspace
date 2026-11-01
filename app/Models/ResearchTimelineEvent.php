<?php

// OpenRiC Workspace - an event on a research project's timeline (roadmap phase 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'user_id', 'label', 'description', 'date_start', 'date_end', 'date_type_id', 'source_table', 'source_id', 'position', 'color'])]
class ResearchTimelineEvent extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_timeline_events';

    protected function casts(): array
    {
        return ['date_start' => 'date', 'date_end' => 'date'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dateType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }
}
