<?php

// OpenRiC Workspace - an ethics approval of a research project (roadmap phase 19h, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'title', 'approval_type_id', 'reference_number', 'committee_name', 'status_id', 'decision_date', 'expiry_date', 'consent_basis_id', 'data_sensitivity_id', 'notes', 'dmp_id', 'created_by_id'])]
class ResearchEthicsApproval extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_ethics_approvals';

    protected function casts(): array
    {
        return ['decision_date' => 'date', 'expiry_date' => 'date'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }

    public function approvalType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function consentBasis(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function dataSensitivity(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function dmp(): BelongsTo
    {
        return $this->belongsTo(ResearchDmp::class);
    }
}
