<?php

// OpenRiC Workspace - a file of an item of a researcher's submission (roadmap phase 19e, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['item_id', 'file_id', 'original_name', 'caption', 'sort_order', 'published_instantiation_id', 'created_at'])]
class ResearcherSubmissionFile extends Model
{
    use Audited, HasUuids;

    protected $table = 'researcher_submission_files';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ResearcherSubmissionItem::class);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(ResearchFile::class);
    }

    public function publishedInstantiation(): BelongsTo
    {
        return $this->belongsTo(Instantiation::class, 'published_instantiation_id');
    }
}
