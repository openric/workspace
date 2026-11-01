<?php

// OpenRiC Workspace - a record in an account's or a visitor's cart (Heratio's cart; roadmap phase 19a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'user_id', 'session_id', 'record_resource_id', 'product_type_id', 'quantity', 'notes'])]
class CartItem extends Model
{
    use Audited, HasUuids;

    protected $table = 'cart_items';

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function productType(): BelongsTo
    {
        return $this->belongsTo(ProductType::class, 'product_type_id');
    }
}
