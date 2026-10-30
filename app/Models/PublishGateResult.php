<?php

// OpenRiC Workspace - one evaluation of a publish gate rule on a description (PSIS's ahg_publish_gate_result), kept as
// its history.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'record_resource_id', 'rule_id', 'status', 'details', 'evaluated_at', 'evaluated_by_id'])]
class PublishGateResult extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['evaluated_at' => 'immutable_datetime'];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(PublishGateRule::class, 'rule_id');
    }
}
