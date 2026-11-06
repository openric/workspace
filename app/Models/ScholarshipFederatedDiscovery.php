<?php

// OpenRiC Workspace - what federation peers hold that shares a description's access points, and what a model said of each link, kept for a day (Heratio's ahg_scholarship_federated_discovery; phase 17d4).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'record_resource_id', 'terms', 'connections', 'peer_stats', 'connection_count', 'ai_available', 'inference_id', 'generated_at'])]
class ScholarshipFederatedDiscovery extends Model
{
    use Audited, HasUuids;

    protected $table = 'scholarship_federated_discoveries';

    protected function casts(): array
    {
        return ['terms' => 'array', 'connections' => 'array', 'peer_stats' => 'array', 'ai_available' => 'boolean', 'generated_at' => 'immutable_datetime'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }
}
