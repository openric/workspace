<?php

// OpenRiC Workspace - a change of a vendor transaction's status, by whom and why (Heratio's ahg_vendor_transaction_history; roadmap phase 22d).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['transaction_id', 'status_from_id', 'status_to_id', 'changed_by_id', 'notes', 'created_at'])]
class VendorTransactionHistory extends Model
{
    use HasUuids;

    protected $table = 'vendor_transaction_history';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_from_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_to_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_id');
    }
}
