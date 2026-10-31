<?php

// OpenRiC Workspace - one check of a record's IIIF manifest and its outcome (PSIS's iiif_validation_result; roadmap
// phase 14b, ADR 0014). The results of one run share its run_id.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'record_resource_id', 'check_id', 'status_id', 'details', 'run_id', 'validated_by_id', 'validated_at'])]
class IiifValidationResult extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['validated_at' => 'immutable_datetime'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'check_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }
}
