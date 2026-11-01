<?php

// OpenRiC Workspace - a section of a writing studio document (roadmap phase 19g, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['doc_id', 'heading', 'body', 'sort_order'])]
class ResearchWritingSection extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_writing_sections';

    public function doc(): BelongsTo
    {
        return $this->belongsTo(ResearchWritingDoc::class);
    }
}
