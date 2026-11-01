<?php

// OpenRiC Workspace - equipment of a reading room (Heratio's research_equipment; roadmap phase 19b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['reading_room_id', 'name', 'code', 'equipment_type_id', 'brand', 'model', 'serial_number', 'description', 'location', 'requires_training', 'max_booking_hours', 'booking_increment_minutes', 'condition_id', 'last_maintenance_on', 'next_maintenance_on', 'is_available', 'notes'])]
class ReadingRoomEquipment extends Model
{
    use Audited, HasUuids;

    protected $table = 'reading_room_equipment';

    protected function casts(): array
    {
        return ['requires_training' => 'boolean', 'max_booking_hours' => 'integer', 'booking_increment_minutes' => 'integer', 'last_maintenance_on' => 'immutable_date', 'next_maintenance_on' => 'immutable_date', 'is_available' => 'boolean'];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(ReadingRoom::class, 'reading_room_id');
    }

    public function equipmentType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'equipment_type_id');
    }

    public function condition(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'condition_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(EquipmentBooking::class, 'equipment_id');
    }

    public function maintenance(): HasMany
    {
        return $this->hasMany(EquipmentMaintenance::class, 'equipment_id')->orderByDesc('performed_at');
    }
}
