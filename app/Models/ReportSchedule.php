<?php

// OpenRiC Workspace - when a built report is made and to whom it is sent (roadmap phase 8; PSIS ahgReportBuilderPlugin, Heratio ahg-reports report_schedule).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['report_id', 'frequency_id', 'day_of_week', 'day_of_month', 'time_of_day', 'format', 'recipients', 'active', 'last_run_at', 'next_run_at', 'created_by_id'])]
class ReportSchedule extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['recipients' => 'array', 'active' => 'boolean', 'last_run_at' => 'immutable_datetime', 'next_run_at' => 'immutable_datetime'];
    }

    /** A run moves the next run on; that alone is not worth an audit row. */
    public function notAudited(): array
    {
        return ['last_run_at', 'next_run_at'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(CustomReport::class, 'report_id');
    }

    public function frequency(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'frequency_id');
    }
}
