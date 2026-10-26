<?php

// OpenRiC Workspace - a right held by a unit of description or an instantiation, a rico:Rule (WS-007, roadmap phase 2).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'iri', 'subject_table', 'subject_id', 'basis_id', 'statement_id', 'licence_id', 'copyright_status_id', 'jurisdiction',
    'determined_on', 'citation', 'starts_on', 'ends_on', 'holder_id', 'note', 'note_language', 'deleted_at'])]
class Right extends Model
{
    use Audited, HasUuids;

    /** The URL path segment of its persistent IRI: {base IRI}/rule/{UUID}, as it is a rico:Rule. */
    public const IRI_SEGMENT = 'rule';

    protected function casts(): array
    {
        return ['determined_on' => 'immutable_date', 'starts_on' => 'immutable_date', 'ends_on' => 'immutable_date', 'deleted_at' => 'immutable_datetime'];
    }

    public function basis(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'basis_id');
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'statement_id');
    }

    public function licence(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'licence_id');
    }

    public function copyrightStatus(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'copyright_status_id');
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'holder_id');
    }

    public function grants(): HasMany
    {
        return $this->hasMany(RightGrant::class)->orderBy('created_at');
    }

    /** Is it in force on the day (today by default): not deleted, begun and not ended? */
    public function inForce(\DateTimeInterface|string|null $on = null): bool
    {
        $day = CarbonImmutable::parse($on ?? now())->startOfDay();

        return $this->deleted_at === null && ($this->starts_on === null || $this->starts_on <= $day) && ($this->ends_on === null || $this->ends_on >= $day);
    }
}
