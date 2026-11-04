<?php

// OpenRiC Workspace - something done to an information package, as a PREMIS event (Heratio's
// preservation_package_event; roadmap phase 15d).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['package_id', 'event_type_id', 'outcome_id', 'detail', 'outcome_detail', 'agent_name', 'user_id', 'occurred_at'])]
class PackageEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'package_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'event_type_id');
    }

    public function outcome(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'outcome_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
