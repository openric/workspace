<?php

// OpenRiC Workspace - a payment of an order, or a gateway notification that was refused (Heratio's ahg_payment and ahg_payment_notifications; roadmap phase 19a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'gateway', 'transaction_id', 'amount', 'currency', 'status_id', 'gateway_response', 'refused_because', 'source_ip', 'paid_at'])]
class Payment extends Model
{
    use Audited, HasUuids;

    protected $table = 'payments';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'gateway_response' => 'array', 'paid_at' => 'immutable_datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }
}
