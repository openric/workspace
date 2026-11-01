<?php

// OpenRiC Workspace - a seat of a reading room (Heratio's research_reading_room_seat; roadmap phase 19b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['reading_room_id', 'seat_number', 'seat_label', 'seat_type_id', 'has_power', 'has_lamp', 'has_computer', 'has_magnifier', 'zone', 'notes', 'is_active', 'position'])]
class ReadingRoomSeat extends Model
{
    use Audited, HasUuids;

    protected $table = 'reading_room_seats';

    protected function casts(): array
    {
        return ['has_power' => 'boolean', 'has_lamp' => 'boolean', 'has_computer' => 'boolean', 'has_magnifier' => 'boolean', 'is_active' => 'boolean'];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(ReadingRoom::class, 'reading_room_id');
    }

    public function seatType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'seat_type_id');
    }
}
