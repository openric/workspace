<?php

// OpenRiC Workspace - a type a display standard gives a unit from its own list: RAD's general material designation,
// the DCMI type or the MODS type of resource (Heratio's materialTypeIds, dcTypeIds and modsTypeIds).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'record_resource_id', 'type_id'])]
class RecordResourceType extends Model
{
    use Audited, HasUuids;

    public function type(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'type_id');
    }
}
