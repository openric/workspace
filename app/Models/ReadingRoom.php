<?php

// OpenRiC Workspace - a reading room: its hours, capacity, rules and booking limits (Heratio's research_reading_room; roadmap phase 19b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'repository_id', 'name', 'code', 'description', 'amenities', 'capacity', 'has_seat_management', 'walk_ins_allowed', 'walk_in_capacity', 'location', 'floor_plan_file_id', 'operating_hours', 'rules', 'advance_booking_days', 'max_booking_hours', 'cancellation_hours', 'opening_time', 'closing_time', 'days_open', 'is_active'])]
class ReadingRoom extends Model
{
    use Audited, HasUuids;

    protected $table = 'reading_rooms';

    protected function casts(): array
    {
        return ['capacity' => 'integer', 'has_seat_management' => 'boolean', 'walk_ins_allowed' => 'boolean', 'walk_in_capacity' => 'integer', 'advance_booking_days' => 'integer', 'max_booking_hours' => 'integer', 'cancellation_hours' => 'integer', 'days_open' => 'array', 'is_active' => 'boolean'];
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function floorPlan(): BelongsTo
    {
        return $this->belongsTo(ResearchFile::class, 'floor_plan_file_id');
    }

    public function seats(): HasMany
    {
        return $this->hasMany(ReadingRoomSeat::class);
    }

    public function equipment(): HasMany
    {
        return $this->hasMany(ReadingRoomEquipment::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(RetrievalSchedule::class);
    }
}
