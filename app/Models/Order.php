<?php

// OpenRiC Workspace - an order of the shop (Heratio's ahg_order; roadmap phase 19a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'reference', 'user_id', 'status_id', 'subtotal', 'vat_amount', 'total', 'currency', 'customer_name', 'customer_email', 'customer_phone', 'billing_address', 'shipping_address', 'notes', 'payment_reference', 'paid_at', 'completed_at', 'cancelled_at'])]
class Order extends Model
{
    use Audited, HasUuids;

    protected $table = 'orders';

    protected function casts(): array
    {
        return ['subtotal' => 'decimal:2', 'vat_amount' => 'decimal:2', 'total' => 'decimal:2', 'paid_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'order_id');
    }
}
