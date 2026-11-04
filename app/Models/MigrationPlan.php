<?php

// OpenRiC Workspace - a migration plan: the institution's files of one format, carried into another by a pathway,
// once someone other than who drew it up approves it (Heratio's preservation_migration_plan; roadmap phase 15c).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'name', 'description', 'source_puid', 'target_puid', 'pathway_id', 'status_id', 'repository_id', 'record_resource_id', 'run_fixity_after', 'scheduled_start', 'created_by_id', 'approved_by_id', 'approved_at', 'started_at', 'completed_at'])]
class MigrationPlan extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['run_fixity_after' => 'boolean', 'scheduled_start' => 'immutable_datetime', 'approved_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    public function pathway(): BelongsTo
    {
        return $this->belongsTo(MigrationPathway::class, 'pathway_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class, 'repository_id');
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(MigrationPlanFile::class, 'plan_id');
    }
}
