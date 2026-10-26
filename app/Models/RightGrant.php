<?php

// OpenRiC Workspace - what a right allows, disallows or conditions: one PREMIS act (WS-007, roadmap phase 2).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['right_id', 'act_id', 'restriction_id', 'starts_on', 'ends_on', 'note'])]
class RightGrant extends Model
{
    use Audited, HasUuids;

    protected $table = 'rights_grants';

    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'ends_on' => 'immutable_date'];
    }

    public function right(): BelongsTo
    {
        return $this->belongsTo(Right::class);
    }

    public function act(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'act_id');
    }

    public function restriction(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'restriction_id');
    }
}
