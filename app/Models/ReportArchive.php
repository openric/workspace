<?php

// OpenRiC Workspace - a file made from a built report, kept to download again (roadmap phase 8; PSIS ahgReportBuilderPlugin, Heratio ahg-reports report_archive).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['report_id', 'schedule_id', 'format', 'path', 'size_bytes', 'entities', 'generated_by_id', 'generated_at'])]
class ReportArchive extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['generated_at' => 'immutable_datetime'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(CustomReport::class, 'report_id');
    }
}
