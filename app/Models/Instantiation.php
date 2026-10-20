<?php

// OpenRiC Workspace - one carrier of a unit of description, such as a digital file or a paper copy (rico:Instantiation; WS-007).
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

#[Fillable(['institution_id', 'iri', 'record_resource_id', 'carrier_type_id', 'availability_id', 'position'])]
class Instantiation extends Model
{
    use Audited, Describable, HasDates, HasIdentifiers, HasUuids;

    /** The URL path segment of its persistent IRI: {base IRI}/instantiation/{UUID}. */
    public const IRI_SEGMENT = 'instantiation';

    protected function casts(): array
    {
        return ['deleted_at' => 'immutable_datetime'];
    }

    /** rico:isOrWasInstantiationOf */
    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    /** rico:hasCarrierType */
    public function carrierType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'carrier_type_id');
    }

    public function availability(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'availability_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(DigitalFile::class);
    }

    /** The file of one use (file-use notation: master, reference, thumbnail), or null. */
    public function file(string $use): ?DigitalFile
    {
        return $this->files->first(fn (DigitalFile $f) => $f->use->notation === $use);
    }
}
