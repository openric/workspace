<?php

// OpenRiC Workspace - an entry of a researcher's diary (roadmap phase 19d, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'user_id', 'entry_date', 'title', 'content', 'entry_type_id', 'time_spent_minutes', 'tags', 'is_private', 'related_table', 'related_id', 'project_id'])]
class ResearchDiaryEntry extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_diary_entries';

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'is_private' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entryType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    /** The research project it belongs to (phase 19f). */
    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }
}
