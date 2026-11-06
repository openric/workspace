<?php

// OpenRiC Workspace - what a model found in the catalogue graph around a description, with the verified links it rests on, its confidence and its receipt (Heratio's ahg_scholarship_discovery; phase 17d4).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'record_resource_id', 'title', 'summary', 'connection_count', 'confidence', 'evidence', 'inference_id', 'generated_at'])]
class ScholarshipDiscovery extends Model
{
    use Audited, HasUuids;

    protected $table = 'scholarship_discoveries';

    protected function casts(): array
    {
        return ['evidence' => 'array', 'generated_at' => 'immutable_datetime'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function inference(): BelongsTo
    {
        return $this->belongsTo(AiInference::class, 'inference_id');
    }
}
