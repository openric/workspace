<?php

// OpenRiC Workspace - a DOI registered at DataCite for a research project or one of its outputs (roadmap phase 19h, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'output_id', 'doi', 'state_id', 'url', 'metadata', 'test', 'minted_by_id'])]
class ResearchDoi extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_dois';

    protected function casts(): array
    {
        return ['metadata' => 'array', 'test' => 'boolean'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }

    public function output(): BelongsTo
    {
        return $this->belongsTo(ResearchProjectOutput::class);
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function mintedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'minted_by_id');
    }
}
