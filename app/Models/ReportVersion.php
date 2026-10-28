<?php

// OpenRiC Workspace - a snapshot of a built report's definition, to restore (roadmap phase 8; PSIS ahgReportBuilderPlugin VersionService, Heratio ahg-reports report_version).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['report_id', 'number', 'snapshot', 'summary', 'created_by_id', 'created_at'])]
class ReportVersion extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'created_at' => 'immutable_datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
