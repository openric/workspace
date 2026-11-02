<?php

// OpenRiC Workspace - a compartment: material that needs a need to know on top of a level (roadmap phase 18c, ADR 0016).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'code', 'name', 'description', 'min_level_id', 'requires_need_to_know', 'requires_briefing', 'is_active'])]
class SecurityCompartment extends Model
{
    use Audited, HasUuids;

    protected $table = 'security_compartments';

    protected function casts(): array
    {
        return ['requires_need_to_know' => 'boolean', 'requires_briefing' => 'boolean', 'is_active' => 'boolean'];
    }

    public function minLevel(): BelongsTo
    {
        return $this->belongsTo(SecurityLevel::class, 'min_level_id');
    }
}
