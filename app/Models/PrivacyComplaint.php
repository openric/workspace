<?php

// OpenRiC Workspace - a privacy complaint (Heratio's privacy_complaint); roadmap phase 18a, ADR 0016.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'reference', 'jurisdiction_id', 'complainant_name', 'complainant_email', 'complainant_phone', 'type_id', 'description', 'incident_on', 'status_id', 'assigned_to_id', 'resolution', 'received_on', 'resolved_on', 'created_by_id'])]
class PrivacyComplaint extends Model
{
    use Audited, HasUuids;

    protected $table = 'privacy_complaints';

    protected function casts(): array
    {
        return ['incident_on' => 'immutable_date', 'received_on' => 'immutable_date', 'resolved_on' => 'immutable_date'];
    }

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(PrivacyJurisdiction::class, 'jurisdiction_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'type_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
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
