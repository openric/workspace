<?php

// OpenRiC Workspace - a request for access to a classified record above one's clearance (roadmap phase 18c, ADR 0016).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'record_resource_id', 'request_type_id', 'priority_id', 'justification', 'status_id', 'reviewed_by_id', 'reviewed_at', 'review_notes', 'duration_hours'])]
class ClearanceRequest extends Model
{
    use Audited, HasUuids;

    protected $table = 'clearance_requests';

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime', 'duration_hours' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function requestType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }
}
