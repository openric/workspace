<?php

// OpenRiC Workspace - a version of a unit of description or an authority record (roadmap phase 22b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Not audited itself: it is written because an audited change was made, and restoring one is audited value by value.

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'subject_table', 'subject_id', 'version_number', 'snapshot', 'changed_fields', 'change_summary', 'created_by_id', 'is_restore', 'restored_from_version', 'created_at'])]
class EntityVersion extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'changed_fields' => 'array', 'version_number' => 'integer', 'is_restore' => 'boolean', 'restored_from_version' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
