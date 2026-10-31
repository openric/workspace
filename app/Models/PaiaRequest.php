<?php

// OpenRiC Workspace - a request for access to a record under PAIA (Heratio's privacy_paia_request); roadmap phase 18a, ADR 0016.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'reference', 'kind_id', 'requester_name', 'requester_email', 'requester_phone', 'requester_id_number', 'requester_address', 'record_description', 'access_form_id', 'status_id', 'refusal_ground_id', 'outcome', 'fee_request', 'fee_deposit', 'fee_access', 'fee_paid', 'fee_exemption_requested', 'received_on', 'due_on', 'completed_on', 'assigned_to_id', 'created_by_id'])]
class PaiaRequest extends Model
{
    use Audited, HasUuids;

    protected $table = 'paia_requests';

    protected function casts(): array
    {
        return ['fee_paid' => 'boolean', 'fee_exemption_requested' => 'boolean', 'received_on' => 'immutable_date', 'due_on' => 'immutable_date', 'completed_on' => 'immutable_date'];
    }

    public function kind(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'kind_id');
    }

    public function accessForm(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'access_form_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function refusalGround(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'refusal_ground_id');
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
