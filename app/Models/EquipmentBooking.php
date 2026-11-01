<?php

// OpenRiC Workspace - equipment booked by a researcher (Heratio's research_equipment_booking; roadmap phase 19b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['booking_id', 'researcher_id', 'equipment_id', 'booking_date', 'start_time', 'end_time', 'purpose', 'status_id', 'checked_out_at', 'checked_out_by_id', 'returned_at', 'returned_by_id', 'condition_on_return_id', 'return_notes', 'notes'])]
class EquipmentBooking extends Model
{
    use Audited, HasUuids;

    protected $table = 'equipment_bookings';

    protected function casts(): array
    {
        return ['booking_date' => 'immutable_date', 'checked_out_at' => 'immutable_datetime', 'returned_at' => 'immutable_datetime'];
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(ReadingRoomEquipment::class, 'equipment_id');
    }

    public function researcher(): BelongsTo
    {
        return $this->belongsTo(Researcher::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function conditionOnReturn(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'condition_on_return_id');
    }
}
