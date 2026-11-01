<?php

// OpenRiC Workspace - a queue of material requests for staff (Heratio's research_request_queue; roadmap phase 19b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'name', 'code', 'description', 'queue_type_id', 'filter_statuses', 'filter_room_id', 'filter_priority', 'sort_field', 'sort_direction', 'auto_assign', 'assigned_staff_id', 'is_default', 'is_active', 'position'])]
class RequestQueue extends Model
{
    use Audited, HasUuids;

    protected $table = 'request_queues';

    protected function casts(): array
    {
        return ['filter_statuses' => 'array', 'auto_assign' => 'boolean', 'is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    public function queueType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'queue_type_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(ReadingRoom::class, 'filter_room_id');
    }

    public function assignedStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_staff_id');
    }
}
