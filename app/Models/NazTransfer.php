<?php

// OpenRiC Workspace - a transfer of records to the National Archives of Zimbabwe (roadmap phase 18e, ADR 0016).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'transfer_number', 'transferring_agency', 'agency_contact', 'agency_email', 'agency_phone', 'schedule_id', 'transfer_type_id', 'description', 'date_range_start', 'date_range_end', 'quantity_linear_metres', 'quantity_boxes', 'quantity_items', 'contains_restricted', 'restriction_details', 'accession_number', 'proposed_date', 'actual_date', 'received_by_id', 'status_id', 'rejection_reason', 'location_assigned', 'notes', 'created_by_id'])]
class NazTransfer extends Model
{
    use Audited, HasUuids;

    protected $table = 'naz_transfers';

    protected function casts(): array
    {
        return ['date_range_start' => 'date', 'date_range_end' => 'date', 'quantity_linear_metres' => 'decimal:2', 'quantity_boxes' => 'integer', 'quantity_items' => 'integer', 'contains_restricted' => 'boolean', 'proposed_date' => 'date', 'actual_date' => 'date'];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(NazRecordsSchedule::class);
    }

    public function transferType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_id');
    }
}
