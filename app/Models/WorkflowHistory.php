<?php

// OpenRiC Workspace - one thing done in a workflow (Heratio's ahg_workflow_history): the action, the status before and
// after, who did it and when, a comment, and the bulk operation it was part of.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'task_id', 'workflow_id', 'step_id', 'subject_table', 'subject_id', 'action_id', 'from_status_id', 'to_status_id', 'performed_by_id',
    'performed_at', 'comment', 'metadata', 'correlation_id'])]
class WorkflowHistory extends Model
{
    use HasUuids;

    protected $table = 'workflow_history';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['performed_at' => 'immutable_datetime', 'metadata' => 'array'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(WorkflowTask::class, 'task_id');
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'step_id');
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'action_id');
    }

    public function fromStatus(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'from_status_id');
    }

    public function toStatus(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'to_status_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_id');
    }
}
