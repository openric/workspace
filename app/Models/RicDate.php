<?php

// OpenRiC Workspace - a date as an entity (rico:Date; ADR 0006).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'iri', 'subject_table', 'subject_id', 'property_id', 'date_type_id', 'qualifier_id', 'normalized_value', 'expressed', 'expressed_language', 'earliest', 'latest'])]
class RicDate extends Model
{
    use Audited, HasUuids;

    protected $table = 'dates';

    /** The URL path segment of its persistent IRI: {base IRI}/date/{UUID}. */
    public const IRI_SEGMENT = 'date';

    protected function casts(): array
    {
        return ['earliest' => 'immutable_date', 'latest' => 'immutable_date'];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'property_id');
    }

    public function dateType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'date_type_id');
    }

    public function qualifier(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'qualifier_id');
    }

    /** How the date reads: its written form, or else its normalised value with its qualifier. */
    public function display(): string
    {
        if ($this->expressed) {
            return $this->expressed;
        }

        return trim(($this->qualifier ? $this->qualifier->label(['en', 'und']).' ' : '').$this->normalized_value);
    }
}
