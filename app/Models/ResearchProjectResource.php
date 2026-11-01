<?php

// OpenRiC Workspace - a resource of a research project (roadmap phase 19f, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'resource_type_id', 'resource_id', 'record_id', 'file_id', 'external_url', 'link_type_id', 'link_metadata', 'title', 'description', 'notes', 'tags', 'added_by_id', 'sort_order', 'added_at'])]
class ResearchProjectResource extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_project_resources';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['link_metadata' => 'array', 'added_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }

    public function resourceType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(ResearchFile::class);
    }

    public function linkType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_id');
    }
}
