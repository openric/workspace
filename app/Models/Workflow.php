<?php

// OpenRiC Workspace - a workflow of the institution (Heratio's ahg_workflow): its name, where it applies, what starts
// it, what it moves, its settings and its steps in order.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'name', 'description', 'scope_id', 'scope_repository_id', 'scope_record_id', 'trigger_id', 'applies_to', 'is_active', 'is_default',
    'require_all_steps', 'allow_parallel', 'auto_archive_days', 'notification_enabled', 'created_by_id'])]
class Workflow extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_default' => 'boolean', 'require_all_steps' => 'boolean', 'allow_parallel' => 'boolean', 'notification_enabled' => 'boolean'];
    }

    public function scope(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'scope_id');
    }

    public function trigger(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'trigger_id');
    }

    public function scopeRepository(): BelongsTo
    {
        return $this->belongsTo(Repository::class, 'scope_repository_id');
    }

    public function scopeRecord(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'scope_record_id');
    }

    /** Its steps in order, inactive ones too. */
    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class)->orderBy('position');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(WorkflowTask::class);
    }
}
