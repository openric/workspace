<?php

// OpenRiC Workspace - a scan of a unit of description for personal information (Heratio's ahg_pii_scan_report;
// roadmap phase 18b, ADR 0016): what it read, what it found, and the decision on the record's files.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'record_resource_id', 'jurisdiction_id', 'started_at', 'finished_at', 'hits_total', 'hits_by_type', 'findings', 'coverage_id', 'status_id',
    'scanned_by_id', 'decided_by_id', 'decided_at', 'notes'])]
class PiiScanReport extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime', 'hits_total' => 'integer',
            'hits_by_type' => 'array', 'findings' => 'array'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(PrivacyJurisdiction::class);
    }

    public function coverage(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'coverage_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function scannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }
}
