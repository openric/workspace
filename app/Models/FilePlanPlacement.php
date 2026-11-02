<?php

// OpenRiC Workspace - a record's place in the file plan (roadmap phase 13c, ADR 0015).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['record_resource_id', 'file_plan_node_id', 'rule_id', 'placed_by_id', 'placed_at'])]
class FilePlanPlacement extends Model
{
    use Audited, HasUuids;

    protected $table = 'file_plan_placements';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['placed_at' => 'datetime'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(FilePlanNode::class, 'file_plan_node_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(ClassificationRule::class);
    }

    public function placedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'placed_by_id');
    }
}
