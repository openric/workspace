<?php

// OpenRiC Workspace - something a researcher captured, to sort later (roadmap phase 19j, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'kind_id', 'origin_id', 'status_id', 'title', 'body', 'source_url', 'file_id', 'project_id', 'triaged_at'])]
class ResearchInboxItem extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_inbox_items';

    protected function casts(): array
    {
        return ['triaged_at' => 'datetime'];
    }

    public function kind(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function origin(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(ResearchFile::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }
}
