<?php

// OpenRiC Workspace - a researcher's visit to a reading room (Heratio's research_booking; roadmap phase 19b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'researcher_id', 'reading_room_id', 'booking_date', 'start_time', 'end_time', 'purpose', 'status_id', 'confirmed_by_id', 'confirmed_at', 'cancelled_at', 'cancellation_reason', 'checked_in_at', 'checked_out_at', 'notes', 'is_walk_in', 'rules_acknowledged_at', 'seat_id', 'project_id'])]
class ReadingRoomBooking extends Model
{
    use Audited, HasUuids;

    protected $table = 'reading_room_bookings';

    protected function casts(): array
    {
        return ['booking_date' => 'immutable_date', 'confirmed_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime', 'checked_in_at' => 'immutable_datetime', 'checked_out_at' => 'immutable_datetime', 'is_walk_in' => 'boolean', 'rules_acknowledged_at' => 'immutable_datetime'];
    }

    public function researcher(): BelongsTo
    {
        return $this->belongsTo(Researcher::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(ReadingRoom::class, 'reading_room_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function seat(): BelongsTo
    {
        return $this->belongsTo(ReadingRoomSeat::class, 'seat_id');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(MaterialRequest::class, 'booking_id');
    }

    public function seatAssignments(): HasMany
    {
        return $this->hasMany(SeatAssignment::class, 'booking_id');
    }

    public function equipmentBookings(): HasMany
    {
        return $this->hasMany(EquipmentBooking::class, 'booking_id');
    }

    /** The research project it belongs to (phase 19f). */
    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }
}
