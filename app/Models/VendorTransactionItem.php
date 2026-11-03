<?php

// OpenRiC Workspace - one record sent to a vendor, with its condition before and after, its declared value and the
// work done on it (Heratio's ahg_vendor_transaction_items; roadmap phase 22d).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['transaction_id', 'record_resource_id', 'condition_before', 'condition_before_grade_id', 'condition_after', 'condition_after_grade_id', 'declared_value',
    'service_description', 'service_completed', 'service_notes', 'item_cost', 'dispatched_at', 'returned_at'])]
class VendorTransactionItem extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['declared_value' => 'decimal:2', 'item_cost' => 'decimal:2', 'service_completed' => 'boolean', 'dispatched_at' => 'immutable_datetime', 'returned_at' => 'immutable_datetime'];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(VendorTransaction::class, 'transaction_id');
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function gradeBefore(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'condition_before_grade_id');
    }

    public function gradeAfter(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'condition_after_grade_id');
    }
}
