<?php

// OpenRiC Workspace - a container in which records are kept, such as a box or a folder (openricx:Container, extension ontology 1.6.0).
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

#[Fillable(['institution_id', 'iri', 'container_type_id', 'status_id', 'security_level_id', 'width_cm', 'height_cm', 'depth_cm', 'capacity', 'capacity_used',
    'capacity_unit_id', 'climate_controlled', 'temperature_min', 'temperature_max', 'humidity_min', 'humidity_max'])]
class Container extends Model
{
    use Audited, Describable, HasDates, HasIdentifiers, HasUuids;

    /** The URL path segment of its persistent IRI: {base IRI}/container/{UUID}. */
    public const IRI_SEGMENT = 'container';

    /** The bundled container-type vocabulary. */
    public const TYPES = 'https://workspace.openric.org/vocab/container-type/';

    /** The facts kept for collection management, in form order. */
    public const FACTS = ['width_cm', 'height_cm', 'depth_cm', 'capacity', 'capacity_used', 'climate_controlled', 'temperature_min', 'temperature_max', 'humidity_min', 'humidity_max'];

    protected function casts(): array
    {
        return ['deleted_at' => 'immutable_datetime', 'climate_controlled' => 'boolean'];
    }

    /** openricx:hasContainerType */
    public function containerType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'container_type_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function securityLevel(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'security_level_id');
    }

    public function capacityUnit(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'capacity_unit_id');
    }
}
