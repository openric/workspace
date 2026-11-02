<?php

// OpenRiC Workspace - a placement made by a classification rule (roadmap phase 13c, ADR 0015).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['record_resource_id', 'email_capture_id', 'rule_id', 'file_plan_node_id', 'match_detail', 'classified_at'])]
class ClassificationLogEntry extends Model
{
    use Audited, HasUuids;

    protected $table = 'classification_log';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['classified_at' => 'datetime'];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(ClassificationRule::class);
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(FilePlanNode::class, 'file_plan_node_id');
    }
}
