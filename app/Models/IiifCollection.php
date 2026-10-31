<?php

// OpenRiC Workspace - a curated IIIF collection (Heratio's iiif_collection; roadmap phase 14a, ADR 0014): a named,
// ordered list of records, other curated collections and other institutions' IIIF resources, published as a IIIF
// Presentation 3 Collection when public.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'parent_id', 'label', 'summary', 'language', 'attribution', 'behavior_id', 'nav_date', 'position', 'is_public', 'created_by_id'])]
class IiifCollection extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['is_public' => 'boolean', 'nav_date' => 'immutable_date'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function behavior(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'behavior_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(IiifCollectionItem::class)->orderBy('position')->orderBy('created_at');
    }
}
