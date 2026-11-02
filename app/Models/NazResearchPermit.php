<?php

// OpenRiC Workspace - a research permit of the National Archives of Zimbabwe (roadmap phase 18e, ADR 0016).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'permit_number', 'researcher_id', 'permit_type_id', 'research_topic', 'research_purpose', 'start_date', 'end_date', 'fee_amount', 'fee_currency', 'fee_paid', 'fee_receipt', 'payment_date', 'approved_by_id', 'approved_on', 'status_id', 'rejection_reason', 'repository_ids', 'restrictions', 'created_by_id'])]
class NazResearchPermit extends Model
{
    use Audited, HasUuids;

    protected $table = 'naz_research_permits';

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'fee_amount' => 'decimal:2', 'fee_paid' => 'boolean', 'payment_date' => 'date', 'approved_on' => 'date', 'repository_ids' => 'array'];
    }

    public function researcher(): BelongsTo
    {
        return $this->belongsTo(Researcher::class);
    }

    public function permitType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }
}
