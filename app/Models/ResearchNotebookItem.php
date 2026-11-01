<?php

// OpenRiC Workspace - an item of a researcher's notebook (roadmap phase 19d, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['notebook_id', 'item_type_id', 'title', 'body', 'source_record_id', 'saved_search_id', 'ai_output_payload', 'pinned', 'sort_order'])]
class ResearchNotebookItem extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_notebook_items';

    protected function casts(): array
    {
        return ['ai_output_payload' => 'array', 'pinned' => 'boolean'];
    }

    public function notebook(): BelongsTo
    {
        return $this->belongsTo(ResearchNotebook::class);
    }

    public function itemType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function sourceRecord(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'source_record_id');
    }

    public function savedSearch(): BelongsTo
    {
        return $this->belongsTo(SavedSearch::class);
    }
}
