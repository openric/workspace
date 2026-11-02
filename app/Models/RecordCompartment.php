<?php

// OpenRiC Workspace - a compartment a record is in (roadmap phase 18c, ADR 0016).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['record_resource_id', 'compartment_id', 'granted_by_id', 'created_at'])]
class RecordCompartment extends Model
{
    use Audited, HasUuids;

    protected $table = 'record_compartments';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function compartment(): BelongsTo
    {
        return $this->belongsTo(SecurityCompartment::class, 'compartment_id');
    }
}
