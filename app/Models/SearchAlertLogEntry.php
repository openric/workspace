<?php

// OpenRiC Workspace - a saved search run for its alert (Heratio's research_search_alert_log; roadmap phase 19c).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['saved_search_id', 'previous_count', 'new_count', 'new_items_count', 'notification_sent', 'created_at'])]
class SearchAlertLogEntry extends Model
{
    use HasUuids;

    protected $table = 'search_alert_log';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['previous_count' => 'integer', 'new_count' => 'integer', 'new_items_count' => 'integer', 'notification_sent' => 'boolean', 'created_at' => 'immutable_datetime'];
    }
}
