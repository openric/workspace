<?php

// OpenRiC Workspace - a work queue of the institution (Heratio's ahg_workflow_queue): where tasks wait, with the days
// its work should take.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'name', 'slug', 'description', 'position', 'is_active', 'sla_days', 'color'])]
class WorkflowQueue extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(WorkflowTask::class, 'queue_id');
    }
}
