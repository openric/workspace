<?php

// OpenRiC Workspace - a vendor: an authority record the institution sends records to for a service, with its vendor
// profile after Heratio's ahg_vendors (roadmap phase 22d). Its names, contact points and relations are the agent's;
// its bank details are encrypted, as Heratio encrypts them.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'agent_id', 'vendor_code', 'vendor_type_id', 'status_id', 'registration_number', 'vat_number', 'bank_name', 'bank_branch',
    'bank_account_number', 'bank_branch_code', 'bank_account_type', 'has_insurance', 'insurance_provider', 'insurance_policy_number', 'insurance_expiry_date',
    'insurance_coverage_amount', 'quality_rating', 'reliability_rating', 'price_rating', 'is_preferred', 'is_bbbee_compliant', 'notes', 'approved_by_id',
    'approved_at', 'created_by_id'])]
class Vendor extends Model
{
    use Audited, HasUuids;

    public const BANK = ['bank_name', 'bank_branch', 'bank_account_number', 'bank_branch_code', 'bank_account_type'];

    protected $hidden = self::BANK;

    protected function casts(): array
    {
        return [...array_fill_keys(self::BANK, 'encrypted'), 'has_insurance' => 'boolean', 'insurance_expiry_date' => 'immutable_date', 'insurance_coverage_amount' => 'decimal:2',
            'quality_rating' => 'integer', 'reliability_rating' => 'integer', 'price_rating' => 'integer', 'is_preferred' => 'boolean', 'is_bbbee_compliant' => 'boolean',
            'approved_at' => 'immutable_datetime'];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function vendorType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'vendor_type_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function services(): HasMany
    {
        return $this->hasMany(VendorService::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(VendorTransaction::class);
    }
}
