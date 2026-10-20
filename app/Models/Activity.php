<?php

// OpenRiC Workspace - an activity, such as a creation or a marriage (rico:Activity; ADR 0006, WS-003).
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

#[Fillable(['institution_id', 'iri', 'activity_type_id'])]
class Activity extends Model
{
    use Audited, Describable, HasDates, HasIdentifiers, HasUuids;

    /** The URL path segment of its persistent IRI: {base IRI}/activity/{UUID}. */
    public const IRI_SEGMENT = 'activity';

    /** The OpenRiC activity-type vocabulary (mapping section 6.5). */
    public const TYPES = 'https://openric.org/vocab/activity-type/';

    protected function casts(): array
    {
        return ['deleted_at' => 'immutable_datetime'];
    }

    /** rico:hasActivityType */
    public function activityType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'activity_type_id');
    }
}
