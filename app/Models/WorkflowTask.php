<?php

// OpenRiC Workspace - a workflow task (Heratio's ahg_workflow_task): one step of a workflow for one record, who
// submitted it and has it, its status, priority, due date and decision, and the checklist ticked.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use App\Relations\EntityKinds;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'workflow_id', 'step_id', 'subject_table', 'subject_id', 'status_id', 'priority_id', 'submitted_by_id', 'assigned_to_id', 'claimed_at',
    'due_date', 'decision_id', 'decision_comment', 'decision_at', 'decision_by_id', 'checklist_completed', 'metadata', 'previous_task_id', 'retry_count', 'escalated_at', 'queue_id'])]
class WorkflowTask extends Model
{
    use Audited, HasUuids;

    /** The statuses of a task still to be done. */
    public const OPEN = ['pending', 'claimed', 'in-progress', 'returned'];

    protected function casts(): array
    {
        return ['claimed_at' => 'immutable_datetime', 'due_date' => 'immutable_date', 'decision_at' => 'immutable_datetime', 'escalated_at' => 'immutable_datetime',
            'checklist_completed' => 'array', 'metadata' => 'array'];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'step_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'priority_id');
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'decision_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    public function decisionBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decision_by_id');
    }

    public function queue(): BelongsTo
    {
        return $this->belongsTo(WorkflowQueue::class, 'queue_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(WorkflowHistory::class, 'task_id')->orderByDesc('performed_at')->orderByDesc('id');
    }

    /** The record, or other entity, the task is about. */
    public function subject(): ?Model
    {
        $kind = EntityKinds::byTable($this->subject_table);

        return $kind === null ? null : EntityKinds::get($kind)['class']::find($this->subject_id);
    }

    public function isOpen(): bool
    {
        return in_array($this->status->notation, self::OPEN, true);
    }

    public function isOverdue(): bool
    {
        return $this->due_date !== null && $this->due_date->isPast() && ! $this->due_date->isToday() && $this->isOpen();
    }
}
