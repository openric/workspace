<?php

// OpenRiC Workspace - records sent to a vendor for a service and brought back (Heratio's ahg_vendor_transactions;
// roadmap phase 22d): its dates and who approved, sent and received them, costs, payment, insurance and shipping.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'transaction_number', 'vendor_id', 'service_type_id', 'status_id', 'request_date', 'approval_date', 'dispatch_date',
    'expected_return_date', 'completion_date', 'actual_return_date', 'invoice_date', 'payment_date', 'requested_by_id', 'approved_by_id', 'dispatched_by_id',
    'received_by_id', 'estimated_cost', 'actual_cost', 'currency_id', 'payment_status_id', 'total_insured_value', 'insurance_arranged', 'quote_reference',
    'invoice_reference', 'insurance_reference', 'shipping_method', 'tracking_number', 'courier_company', 'dispatch_notes', 'vendor_notes', 'return_notes',
    'internal_notes'])]
class VendorTransaction extends Model
{
    use Audited, HasUuids;

    public const DATES = ['request_date', 'approval_date', 'dispatch_date', 'expected_return_date', 'completion_date', 'actual_return_date', 'invoice_date', 'payment_date'];

    protected function casts(): array
    {
        return [...array_fill_keys(self::DATES, 'immutable_date'), 'estimated_cost' => 'decimal:2', 'actual_cost' => 'decimal:2', 'total_insured_value' => 'decimal:2',
            'insurance_arranged' => 'boolean'];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'service_type_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function paymentStatus(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'payment_status_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'currency_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(VendorTransactionItem::class, 'transaction_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(VendorTransactionHistory::class, 'transaction_id')->orderByDesc('created_at');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(VendorTransactionAttachment::class, 'transaction_id')->orderBy('created_at');
    }

    /** Due back and not back (Heratio's overdue list). */
    public function overdue(): bool
    {
        return $this->expected_return_date !== null && $this->actual_return_date === null && $this->expected_return_date->isPast()
            && ! in_array($this->status?->notation, ['returned', 'cancelled'], true);
    }
}
