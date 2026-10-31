<?php

// OpenRiC Workspace - the disposal class a record is given, and when its retention ends, after Heratio's rm_record_disposal_class (roadmap phase 13b, ADR 0015).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['record_resource_id', 'disposal_class_id', 'assigned_by_id', 'retention_starts_on', 'disposal_due_on', 'override_due_on', 'override_reason'])]
class RecordDisposalClass extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['retention_starts_on' => 'immutable_date', 'disposal_due_on' => 'immutable_date', 'override_due_on' => 'immutable_date'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function disposalClass(): BelongsTo
    {
        return $this->belongsTo(DisposalClass::class, 'disposal_class_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_id');
    }
}
