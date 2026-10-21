<?php

// OpenRiC Workspace - a place (rico:Place; ADR 0006): its names are rico:PlaceName entities.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use App\Description\Describable;
use App\Description\HasDates;
use App\Description\HasIdentifiers;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'iri', 'place_type_id', 'parent_id', 'latitude', 'longitude', 'altitude', 'deleted_at'])]
class Place extends Model
{
    use Audited, Describable, HasDates, HasIdentifiers, HasUuids;

    /** The URL path segment of its persistent IRI: {base IRI}/place/{UUID}. */
    public const IRI_SEGMENT = 'place';

    protected function casts(): array
    {
        return ['deleted_at' => 'immutable_datetime'];
    }

    public function placeType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'place_type_id');
    }

    /** The place it lies in now (rico:isOrWasContainedBy). */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** The places that lie in it now (rico:containsOrContained). */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->whereNull('deleted_at');
    }

    /** @return list<self> the places it lies in, the outermost first */
    public function ancestors(): array
    {
        $chain = [];
        for ($place = $this->parent; $place !== null; $place = $place->parent) {
            array_unshift($chain, $place);
        }

        return $chain;
    }
}
