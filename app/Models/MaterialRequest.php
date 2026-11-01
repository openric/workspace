<?php

// OpenRiC Workspace - a record asked for, for a reading room visit (Heratio's research_material_request; roadmap phase 19b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['booking_id', 'record_resource_id', 'quantity', 'notes', 'request_type_id', 'priority_id', 'handling_instructions', 'location_code', 'shelf_location', 'location_current_id', 'retrieval_scheduled_for', 'queue_id', 'box_number', 'folder_number', 'curatorial_approval_required', 'curatorial_approved_by_id', 'curatorial_approved_at', 'call_slip_printed_at', 'call_slip_printed_by_id', 'status_id', 'unavailable_reason', 'retrieved_by_id', 'retrieved_at', 'returned_at', 'condition_notes', 'sla_due_on', 'assigned_to_id', 'checkout_confirmed_at', 'checkout_confirmed_by_id', 'return_condition_id', 'return_verified_by_id', 'return_verified_at'])]
class MaterialRequest extends Model
{
    use Audited, HasUuids;

    protected $table = 'material_requests';

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'retrieval_scheduled_for' => 'immutable_datetime', 'curatorial_approval_required' => 'boolean', 'curatorial_approved_at' => 'immutable_datetime', 'call_slip_printed_at' => 'immutable_datetime', 'retrieved_at' => 'immutable_datetime', 'returned_at' => 'immutable_datetime', 'sla_due_on' => 'immutable_date', 'checkout_confirmed_at' => 'immutable_datetime', 'return_verified_at' => 'immutable_datetime'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(ReadingRoomBooking::class, 'booking_id');
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function requestType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'request_type_id');
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'priority_id');
    }

    public function locationCurrent(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'location_current_id');
    }

    public function queue(): BelongsTo
    {
        return $this->belongsTo(RequestQueue::class, 'queue_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    public function returnCondition(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'return_condition_id');
    }

    public function statusChanges(): HasMany
    {
        return $this->hasMany(MaterialRequestStatusChange::class)->orderBy('created_at');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MaterialRequestMessage::class)->orderBy('created_at');
    }

    public function handoffs(): HasMany
    {
        return $this->hasMany(CustodyHandoff::class)->orderBy('created_at');
    }
}
