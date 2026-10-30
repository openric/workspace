<?php

// OpenRiC Workspace - a service level policy (Heratio's ahg_workflow_sla_policy): for a queue, a workflow or the
// institution, the days before a task is at risk, due and escalated, and what escalating does.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'name', 'queue_id', 'workflow_id', 'warning_days', 'due_days', 'escalation_days', 'escalation_user_id', 'escalation_action_id', 'is_active'])]
class WorkflowSlaPolicy extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function queue(): BelongsTo
    {
        return $this->belongsTo(WorkflowQueue::class, 'queue_id');
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function escalationUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalation_user_id');
    }

    public function escalationAction(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'escalation_action_id');
    }
}
