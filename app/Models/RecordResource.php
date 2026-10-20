<?php

// OpenRiC Workspace - a record set, record or record part (ISAD(G); RiC-CM record resource).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use App\Description\Describable;
use App\Description\HasDates;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'iri', 'identifier', 'level_id', 'repository_id', 'parent_id', 'position'])]
class RecordResource extends Model
{
    use Audited, Describable, HasDates, HasUuids;

    /** The URL path segment of its persistent IRI: {base IRI}/record/{UUID}. */
    public const IRI_SEGMENT = 'record';

    public function level(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'level_id');
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->whereNull('deleted_at')->orderBy('position');
    }

    /** Ancestors from the top down, not including this one. */
    public function ancestors(): array
    {
        $chain = [];
        for ($node = $this->parent; $node !== null; $node = $node->parent) {
            array_unshift($chain, $node);
        }

        return $chain;
    }

    /** Ids of every unit below this one. */
    public function descendantIds(): array
    {
        $ids = [];
        $frontier = [$this->id];
        while ($frontier !== []) {
            $frontier = self::whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }
}
