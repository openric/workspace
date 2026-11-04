<?php

// OpenRiC Workspace - a request to borrow an item between members of an institution's union, and where it stands
// (Heratio's federation_loan_request; roadmap phase 16d).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'requesting_member_id', 'holding_member_id', 'record_resource_id', 'item_ref', 'item_title', 'purpose', 'status_id', 'needed_from', 'needed_to',
    'notes', 'decided_by_id', 'decided_at', 'created_by_id'])]
class FederationLoanRequest extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['needed_from' => 'immutable_date', 'needed_to' => 'immutable_date', 'decided_at' => 'immutable_datetime'];
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function requesting(): BelongsTo
    {
        return $this->belongsTo(FederationMember::class, 'requesting_member_id');
    }

    public function holding(): BelongsTo
    {
        return $this->belongsTo(FederationMember::class, 'holding_member_id');
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }
}
