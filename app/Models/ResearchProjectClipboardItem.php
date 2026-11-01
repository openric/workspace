<?php

// OpenRiC Workspace - a record on a research project's clipboard (roadmap phase 19f, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'user_id', 'record_id', 'is_pinned', 'notes', 'created_at'])]
class ResearchProjectClipboardItem extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_project_clipboard';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['is_pinned' => 'boolean', 'created_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class);
    }
}
