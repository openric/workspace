<?php

// OpenRiC Workspace - a node of the file plan: a function, activity, series, sub-series or class (roadmap phase 13c, ADR 0015).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'parent_id', 'activity_id', 'node_type_id', 'code', 'title', 'description', 'disposal_class_id', 'retention_period', 'disposal_action_id', 'status_id', 'source_department', 'source_agency_code', 'import_id', 'position', 'created_by_id'])]
class FilePlanNode extends Model
{
    use Audited, HasUuids;

    protected $table = 'file_plan_nodes';

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(FilePlanNode::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(FilePlanNode::class, 'parent_id')->orderBy('position')->orderBy('code');
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function nodeType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function disposalClass(): BelongsTo
    {
        return $this->belongsTo(DisposalClass::class);
    }

    public function disposalAction(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function placements(): HasMany
    {
        return $this->hasMany(FilePlanPlacement::class, 'file_plan_node_id');
    }
}
