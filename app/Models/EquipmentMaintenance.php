<?php

// OpenRiC Workspace - maintenance done on reading room equipment (Heratio's research_equipment_maintenance; roadmap phase 19b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['equipment_id', 'description', 'condition_before', 'condition_after', 'next_maintenance_on', 'performed_by_id', 'performed_at'])]
class EquipmentMaintenance extends Model
{
    use Audited, HasUuids;

    protected $table = 'equipment_maintenance';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['next_maintenance_on' => 'immutable_date', 'performed_at' => 'immutable_datetime'];
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_id');
    }
}
