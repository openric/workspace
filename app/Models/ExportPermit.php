<?php

// OpenRiC Workspace - an application for a permit to export heritage objects under the National Heritage Resources Act, 1999, s.32 (roadmap phase 18d, ADR 0016).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'application_ref', 'permit_number', 'issuing_authority', 'applicant_id', 'purpose', 'description', 'destination_institution', 'destination_country', 'start_date', 'end_date', 'conditions', 'fee_amount', 'fee_paid', 'fee_receipt', 'status_id', 'approved_by_id', 'approved_at', 'approval_notes', 'submitted_at', 'authority_reference', 'decided_at', 'authority_notes'])]
class ExportPermit extends Model
{
    use Audited, HasUuids;

    protected $table = 'export_permits';

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'fee_amount' => 'decimal:2', 'fee_paid' => 'boolean', 'approved_at' => 'datetime', 'submitted_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(ExportPermitRecord::class, 'permit_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ExportPermitDocument::class, 'permit_id');
    }
}
