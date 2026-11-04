<?php

// OpenRiC Workspace - something done to a file, as a PREMIS 3 event (Heratio's preservation_event; roadmap phase 15a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'digital_file_id', 'instantiation_id', 'record_resource_id', 'file_label', 'event_type_id', 'outcome_id', 'detail', 'outcome_detail',
    'agent_type_id', 'agent_name', 'user_id', 'source_file_id', 'occurred_at'])]
class PreservationEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'event_type_id');
    }

    public function outcome(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'outcome_id');
    }

    public function agentType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'agent_type_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
