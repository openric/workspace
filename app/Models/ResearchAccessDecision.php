<?php

// OpenRiC Workspace - a decision taken under research rights policies (roadmap phase 19j, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['policy_id', 'user_id', 'record_resource_id', 'action_id', 'permitted', 'rationale', 'evaluated_at'])]
class ResearchAccessDecision extends Model
{
    use HasUuids;

    protected $table = 'research_access_decisions';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['permitted' => 'boolean', 'evaluated_at' => 'datetime'];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(ResearchRightsPolicy::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }
}
