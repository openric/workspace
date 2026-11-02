<?php

// OpenRiC Workspace - a limit on what researchers download and keep (roadmap phase 19j, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'scope_id', 'scope_key', 'period_id', 'max_downloads', 'max_storage_bytes', 'soft_warn_pct', 'is_active', 'notes'])]
class ResearchQuotaPolicy extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_quota_policies';

    protected function casts(): array
    {
        return ['max_downloads' => 'integer', 'max_storage_bytes' => 'integer', 'soft_warn_pct' => 'integer', 'is_active' => 'boolean'];
    }

    public function scope(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }
}
