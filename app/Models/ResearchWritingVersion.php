<?php

// OpenRiC Workspace - a saved version of a writing studio document (roadmap phase 19g, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['doc_id', 'version_no', 'snapshot', 'note', 'created_by_id', 'created_at'])]
class ResearchWritingVersion extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_writing_versions';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'created_at' => 'datetime'];
    }

    public function doc(): BelongsTo
    {
        return $this->belongsTo(ResearchWritingDoc::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
