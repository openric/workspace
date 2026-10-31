<?php

// OpenRiC Workspace - a review of a record when its retention ends, after Heratio's rm_review_schedule (roadmap phase 13b, ADR 0015).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'record_resource_id', 'disposal_class_id', 'due_on', 'completed_on', 'assigned_to_id', 'decision_id', 'decision_notes', 'next_due_on', 'disposal_action_id', 'created_by_id'])]
class RetentionReview extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['due_on' => 'immutable_date', 'completed_on' => 'immutable_date', 'next_due_on' => 'immutable_date'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function disposalClass(): BelongsTo
    {
        return $this->belongsTo(DisposalClass::class, 'disposal_class_id');
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'decision_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    public function disposalAction(): BelongsTo
    {
        return $this->belongsTo(DisposalAction::class, 'disposal_action_id');
    }
}
