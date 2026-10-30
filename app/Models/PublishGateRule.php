<?php

// OpenRiC Workspace - a publish gate rule (Heratio's and PSIS's ahg_publish_gate_rule): what a description must have
// before it is published, for which level or repository, and whether failing it blocks or warns.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'name', 'rule_type_id', 'level_id', 'repository_id', 'field_name', 'min_length', 'error_message', 'severity_id', 'is_active', 'position'])]
class PublishGateRule extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function ruleType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'rule_type_id');
    }

    public function severity(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'severity_id');
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'level_id');
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }
}
