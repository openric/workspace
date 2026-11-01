<?php

// OpenRiC Workspace - an output of a research project: an article, dataset, presentation and the like (roadmap phase 19f, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'output_type_id', 'title', 'authors', 'venue', 'identifier_type_id', 'identifier', 'identifier_url', 'output_date', 'status_id', 'notes', 'created_by_id'])]
class ResearchProjectOutput extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_project_outputs';

    protected function casts(): array
    {
        return ['output_date' => 'date'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }

    public function outputType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function identifierType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }
}
