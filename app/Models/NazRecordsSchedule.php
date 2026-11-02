<?php

// OpenRiC Workspace - a records schedule of an agency under the National Archives of Zimbabwe Act (roadmap phase 18e, ADR 0016).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'schedule_number', 'agency_name', 'agency_code', 'record_series', 'description', 'retention_active_years', 'retention_semi_active_years', 'disposal_action_id', 'legal_authority', 'records_class_id', 'access_restriction_id', 'disposal_class_id', 'approved_by', 'approval_date', 'effective_date', 'review_date', 'status_id', 'notes', 'created_by_id'])]
class NazRecordsSchedule extends Model
{
    use Audited, HasUuids;

    protected $table = 'naz_records_schedules';

    protected function casts(): array
    {
        return ['retention_active_years' => 'integer', 'retention_semi_active_years' => 'integer', 'approval_date' => 'date', 'effective_date' => 'date', 'review_date' => 'date'];
    }

    public function disposalAction(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function recordsClass(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function accessRestriction(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function disposalClass(): BelongsTo
    {
        return $this->belongsTo(DisposalClass::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }
}
