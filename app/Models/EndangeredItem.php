<?php

// OpenRiC Workspace - a description flagged for prioritised capture, with what threatens it, how urgent it is and
// where its capture stands (Heratio's endangered_heritage_item; phase 17d3).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'record_resource_id', 'risk_id', 'urgency_id', 'capture_status_id', 'reason', 'flagged_by_id'])]
class EndangeredItem extends Model
{
    use Audited, HasUuids;

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'risk_id');
    }

    public function urgency(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'urgency_id');
    }

    public function captureStatus(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'capture_status_id');
    }

    public function flaggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'flagged_by_id');
    }
}
