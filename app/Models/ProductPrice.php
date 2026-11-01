<?php

// OpenRiC Workspace - a product type's price, the institution's or a repository's own (Heratio's ahg_product_pricing; roadmap phase 19a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'repository_id', 'product_type_id', 'price', 'price_includes_vat', 'is_active'])]
class ProductPrice extends Model
{
    use Audited, HasUuids;

    protected $table = 'product_prices';

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'price_includes_vat' => 'boolean', 'is_active' => 'boolean'];
    }

    public function productType(): BelongsTo
    {
        return $this->belongsTo(ProductType::class, 'product_type_id');
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }
}
