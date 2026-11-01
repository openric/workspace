<?php

// OpenRiC Workspace - a research project's method protocol (roadmap phase 19g, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'template_id', 'title', 'fields', 'status_id', 'created_by_id'])]
class ResearchMethodProtocol extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_method_protocols';

    protected function casts(): array
    {
        return ['fields' => 'array'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ResearchMethodTemplate::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
