<?php

// OpenRiC Workspace - a rule for finding duplicate archival descriptions, after Heratio's ahg_duplicate_rule (roadmap phase 22b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'repository_id', 'name', 'rule_type_id', 'threshold', 'is_enabled', 'priority'])]
class DuplicateRule extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['threshold' => 'float', 'is_enabled' => 'boolean', 'priority' => 'integer'];
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class, 'repository_id');
    }

    public function ruleType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'rule_type_id');
    }
}
