<?php

// OpenRiC Workspace - when items are fetched from storage for a reading room (Heratio's research_retrieval_schedule; roadmap phase 19b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['reading_room_id', 'name', 'day_of_week', 'retrieval_time', 'cutoff_minutes_before', 'max_items_per_run', 'storage_location', 'is_active', 'notes'])]
class RetrievalSchedule extends Model
{
    use Audited, HasUuids;

    protected $table = 'retrieval_schedules';

    protected function casts(): array
    {
        return ['day_of_week' => 'integer', 'cutoff_minutes_before' => 'integer', 'max_items_per_run' => 'integer', 'is_active' => 'boolean'];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(ReadingRoom::class, 'reading_room_id');
    }
}
