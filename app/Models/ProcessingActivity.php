<?php

// OpenRiC Workspace - an entry of the processing register, the documentation of POPIA s.17 (Heratio's privacy_processing_activity); roadmap phase 18a, ADR 0016.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'name', 'description', 'jurisdiction_id', 'purpose', 'lawful_basis_id', 'data_categories', 'data_subjects', 'recipients', 'transfers', 'retention_period', 'security_measures', 'special_information', 'children', 'impact_assessment_required', 'status_id', 'owner', 'department', 'officer_id', 'next_review_on', 'submitted_at', 'submitted_by_id', 'decided_at', 'decided_by_id', 'rejection_reason', 'created_by_id'])]
class ProcessingActivity extends Model
{
    use Audited, HasUuids;

    protected $table = 'processing_activities';

    protected function casts(): array
    {
        return ['special_information' => 'boolean', 'children' => 'boolean', 'impact_assessment_required' => 'boolean', 'next_review_on' => 'immutable_date', 'submitted_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime'];
    }

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(PrivacyJurisdiction::class, 'jurisdiction_id');
    }

    public function lawfulBasis(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'lawful_basis_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function officer(): BelongsTo
    {
        return $this->belongsTo(PrivacyOfficer::class, 'officer_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
