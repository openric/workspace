<?php

// OpenRiC Workspace - what the shop sells of a record: a download, a print or a licence (Heratio's ahg_product_type; roadmap phase 19a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'name', 'description', 'is_digital', 'requires_shipping', 'delivers_use', 'is_active', 'position'])]
class ProductType extends Model
{
    use Audited, HasUuids;

    protected $table = 'product_types';

    protected function casts(): array
    {
        return ['is_digital' => 'boolean', 'requires_shipping' => 'boolean', 'is_active' => 'boolean'];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class, 'product_type_id');
    }
}
