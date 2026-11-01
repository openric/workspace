<?php

// OpenRiC Workspace - feedback on the catalogue or a record, from a visitor (Heratio's feedback; roadmap phase 19a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'record_resource_id', 'user_id', 'type_id', 'status_id', 'subject', 'remarks', 'name', 'surname', 'email', 'phone', 'relationship', 'language', 'staff_notes', 'completed_at', 'completed_by_id'])]
class Feedback extends Model
{
    use Audited, HasUuids;

    protected $table = 'feedback';

    protected function casts(): array
    {
        return ['completed_at' => 'immutable_datetime'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'type_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_id');
    }
}
