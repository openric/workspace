<?php

// OpenRiC Workspace - an institution's shop: on or off, currency, VAT and PayFast (Heratio's ahg_ecommerce_settings; roadmap phase 19a). The merchant key and passphrase are encrypted.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['institution_id', 'is_enabled', 'currency', 'vat_rate', 'vat_number', 'terms', 'payfast_merchant_id', 'payfast_merchant_key', 'payfast_passphrase', 'payfast_sandbox', 'download_days', 'download_limit'])]
class ShopSettings extends Model
{
    use Audited, HasUuids;

    protected $table = 'shop_settings';

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'vat_rate' => 'decimal:2', 'payfast_merchant_key' => 'encrypted', 'payfast_passphrase' => 'encrypted', 'payfast_sandbox' => 'boolean', 'download_days' => 'integer', 'download_limit' => 'integer'];
    }
}
