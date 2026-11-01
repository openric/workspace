<?php

// OpenRiC Workspace - a visitor to a reading room without a booking (Heratio's research_walk_in_visitor; roadmap phase 19b). The identity number is encrypted.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['reading_room_id', 'visit_date', 'first_name', 'last_name', 'email', 'phone', 'id_type_id', 'id_number', 'organization', 'purpose', 'research_topic', 'rules_acknowledged_at', 'photo_permission', 'converted_to_researcher_id', 'seat_id', 'check_in_time', 'check_out_time', 'checked_in_by_id', 'checked_out_by_id', 'notes', 'created_at'])]
class WalkInVisitor extends Model
{
    use Audited, HasUuids;

    protected $table = 'walk_in_visitors';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['visit_date' => 'immutable_date', 'phone' => 'encrypted', 'id_number' => 'encrypted', 'rules_acknowledged_at' => 'immutable_datetime', 'photo_permission' => 'boolean', 'check_in_time' => 'immutable_datetime', 'check_out_time' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(ReadingRoom::class, 'reading_room_id');
    }

    public function idType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'id_type_id');
    }

    public function seat(): BelongsTo
    {
        return $this->belongsTo(ReadingRoomSeat::class, 'seat_id');
    }

    public function researcher(): BelongsTo
    {
        return $this->belongsTo(Researcher::class, 'converted_to_researcher_id');
    }
}
