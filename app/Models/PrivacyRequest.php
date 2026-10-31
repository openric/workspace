<?php

// OpenRiC Workspace - a data subject's request to access, correct or delete (POPIA s.23, s.24; Heratio's privacy_dsar); roadmap phase 18a, ADR 0016.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'reference', 'jurisdiction_id', 'type_id', 'requester_name', 'requester_email', 'requester_phone', 'requester_id_type', 'requester_id_number', 'requester_address', 'details', 'verified_at', 'verified_by_id', 'status_id', 'received_on', 'due_on', 'completed_on', 'assigned_to_id', 'outcome', 'refusal_reason', 'fee', 'fee_paid', 'priority_id', 'notes', 'created_by_id'])]
class PrivacyRequest extends Model
{
    use Audited, HasUuids;

    protected $table = 'privacy_requests';

    protected function casts(): array
    {
        return ['verified_at' => 'immutable_datetime', 'received_on' => 'immutable_date', 'due_on' => 'immutable_date', 'completed_on' => 'immutable_date', 'fee_paid' => 'boolean'];
    }

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(PrivacyJurisdiction::class, 'jurisdiction_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'type_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'priority_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
