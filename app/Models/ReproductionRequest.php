<?php

// OpenRiC Workspace - a researcher's request for reproductions (Heratio's research_reproduction_request; roadmap phase 19c).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'researcher_id', 'reference', 'purpose', 'intended_use', 'publication_details', 'status_id', 'estimated_cost', 'final_cost', 'currency', 'payment_reference', 'payment_date', 'payment_method', 'invoice_number', 'invoice_date', 'delivery_method_id', 'delivery_address', 'delivery_email', 'completed_at', 'processed_by_id', 'notes', 'admin_notes', 'sla_due_on', 'assigned_to_id', 'closed_at', 'closed_by_id', 'closure_reason'])]
class ReproductionRequest extends Model
{
    use Audited, HasUuids;

    protected $table = 'reproduction_requests';

    protected function casts(): array
    {
        return ['estimated_cost' => 'decimal:2', 'final_cost' => 'decimal:2', 'payment_date' => 'immutable_date', 'invoice_date' => 'immutable_date', 'completed_at' => 'immutable_datetime', 'sla_due_on' => 'immutable_date', 'closed_at' => 'immutable_datetime'];
    }

    public function researcher(): BelongsTo
    {
        return $this->belongsTo(Researcher::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function deliveryMethod(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'delivery_method_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReproductionItem::class)->orderBy('created_at');
    }

    public function statusChanges(): HasMany
    {
        return $this->hasMany(ReproductionStatusChange::class)->orderBy('created_at');
    }
}
