<?php

// OpenRiC Workspace - a step of a workflow (Heratio's ahg_workflow_step): what it is for and asks, who may take it,
// whether it goes to the pool or to someone, when it escalates, its instructions and checklist.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['workflow_id', 'name', 'description', 'position', 'step_type_id', 'action_id', 'required_role_id', 'required_clearance_level', 'allowed_role_ids',
    'allowed_user_ids', 'pool_enabled', 'auto_assign_user_id', 'escalation_days', 'escalation_user_id', 'notification_template', 'instructions', 'checklist',
    'is_optional', 'is_active'])]
class WorkflowStep extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['allowed_role_ids' => 'array', 'allowed_user_ids' => 'array', 'checklist' => 'array', 'pool_enabled' => 'boolean', 'is_optional' => 'boolean', 'is_active' => 'boolean'];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function stepType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'step_type_id');
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'action_id');
    }

    public function requiredRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'required_role_id');
    }

    public function autoAssignUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auto_assign_user_id');
    }

    public function escalationUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalation_user_id');
    }

    /** PSIS's canUserClaimTask: the required role, the allowed roles and the allowed accounts, each when given. */
    public function allows(User $user): bool
    {
        $roles = $user->roles->pluck('id')->all();

        return ($this->required_role_id === null || in_array($this->required_role_id, $roles, true))
            && ($this->allowed_role_ids === null || $this->allowed_role_ids === [] || array_intersect($this->allowed_role_ids, $roles) !== [])
            && ($this->allowed_user_ids === null || $this->allowed_user_ids === [] || in_array($user->id, $this->allowed_user_ids, true));
    }
}
