<?php

// OpenRiC Workspace - a webhook: another system told when entities change, after Heratio's ahg_webhook (roadmap phase 22c).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['institution_id', 'name', 'url', 'secret', 'events', 'entity_kinds', 'is_active', 'failure_count', 'last_triggered_at', 'created_by_id'])]
class Webhook extends Model
{
    use Audited, HasUuids;

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['events' => 'array', 'entity_kinds' => 'array', 'is_active' => 'boolean', 'failure_count' => 'integer', 'last_triggered_at' => 'immutable_datetime'];
    }
}
