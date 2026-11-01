<?php

// OpenRiC Workspace - a seat given to a booking (Heratio's research_seat_assignment; roadmap phase 19b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['booking_id', 'seat_id', 'assigned_at', 'assigned_by_id', 'released_at', 'released_by_id', 'status_id', 'notes'])]
class SeatAssignment extends Model
{
    use Audited, HasUuids;

    protected $table = 'seat_assignments';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['assigned_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(ReadingRoomBooking::class, 'booking_id');
    }

    public function seat(): BelongsTo
    {
        return $this->belongsTo(ReadingRoomSeat::class, 'seat_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }
}
