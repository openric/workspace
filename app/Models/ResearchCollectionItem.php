<?php

// OpenRiC Workspace - an item of a researcher's collection (roadmap phase 19d, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['collection_id', 'subject_table', 'subject_id', 'external_uri', 'title', 'reference_code', 'tags', 'notes', 'sort_order'])]
class ResearchCollectionItem extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_collection_items';

    public function collection(): BelongsTo
    {
        return $this->belongsTo(ResearchCollection::class);
    }

    /** The outside address, under the name favourites give it, so Favourites::shown shows an item as it shows a favourite. */
    protected function url(): Attribute
    {
        return Attribute::get(fn () => $this->external_uri);
    }
}
