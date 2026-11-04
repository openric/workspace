<?php

// OpenRiC Workspace - something to ask of DataCite for a unit's DOI, waiting its turn (Heratio's ahg_doi_queue; roadmap phase 20c).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'record_resource_id', 'action_id', 'status_id', 'priority', 'attempts', 'max_attempts', 'last_error', 'created_by_id', 'scheduled_at', 'started_at', 'completed_at'])]
class DoiQueueItem extends Model
{
    use HasUuids;

    protected $table = 'doi_queue';

    protected function casts(): array
    {
        return ['scheduled_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'action_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
