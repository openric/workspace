<?php

// OpenRiC Workspace - an item of an accession's intake checklist (PSIS's and Heratio's accession_intake_checklist).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'accession_id', 'label', 'is_completed', 'completed_by_id', 'completed_at', 'position'])]
class AccessionChecklistItem extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['is_completed' => 'boolean', 'completed_at' => 'immutable_datetime'];
    }

    public function accession(): BelongsTo
    {
        return $this->belongsTo(Accession::class);
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_id');
    }
}
