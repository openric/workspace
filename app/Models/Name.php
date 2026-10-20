<?php

// OpenRiC Workspace - a name as an entity (rico:Name; rico:Title for a unit of description; ADR 0006).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use App\Description\HasDates;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'iri', 'subject_table', 'subject_id', 'name_type_id', 'value', 'language', 'is_original', 'state_id'])]
class Name extends Model
{
    use Audited, HasDates, HasUuids;

    /** The URL path segment of its persistent IRI: {base IRI}/name/{UUID}. */
    public const IRI_SEGMENT = 'name';

    protected function casts(): array
    {
        return ['is_original' => 'boolean'];
    }

    public function nameType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'name_type_id');
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'state_id');
    }

    /** The date of the given date-property notation (used-from, used-to), if it has one. */
    public function usedDate(string $property): ?RicDate
    {
        return $this->dates->first(fn (RicDate $date) => $date->property->notation === $property);
    }
}
