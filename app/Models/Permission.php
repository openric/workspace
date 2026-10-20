<?php

// OpenRiC Workspace - one cell of the permission matrix (ADR 0005).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A role or one account; an action on a kind of record; a scope of everything,
// one repository or one record; grant or deny. Inherit is the absence of a row.

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'role_id', 'user_id', 'kind_id', 'action_id', 'repository_id', 'subject_table', 'subject_id', 'is_grant'])]
class Permission extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['is_grant' => 'boolean'];
    }

    public function kind(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'kind_id');
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'action_id');
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /** 'record', 'repository' or 'all'. */
    public function scope(): string
    {
        return $this->subject_id !== null ? 'record' : ($this->repository_id !== null ? 'repository' : 'all');
    }
}
