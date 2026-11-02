<?php

// OpenRiC Workspace - a rule that places records and captured e-mail in the file plan (roadmap phase 13c, ADR 0015).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'name', 'description', 'rule_type_id', 'match_pattern', 'file_plan_node_id', 'disposal_class_id', 'priority', 'is_active', 'apply_on_id', 'created_by_id'])]
class ClassificationRule extends Model
{
    use Audited, HasUuids;

    protected $table = 'classification_rules';

    protected function casts(): array
    {
        return ['priority' => 'integer', 'is_active' => 'boolean'];
    }

    public function ruleType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(FilePlanNode::class, 'file_plan_node_id');
    }

    public function disposalClass(): BelongsTo
    {
        return $this->belongsTo(DisposalClass::class);
    }

    public function applyOn(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }
}
