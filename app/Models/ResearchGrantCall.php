<?php

// OpenRiC Workspace - a funding call a researcher watches (roadmap phase 19h, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'user_id', 'project_id', 'funder', 'title', 'url', 'deadline', 'status_id', 'notes'])]
class ResearchGrantCall extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_grant_calls';

    protected function casts(): array
    {
        return ['deadline' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }
}
