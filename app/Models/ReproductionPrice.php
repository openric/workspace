<?php

// OpenRiC Workspace - what a kind of reproduction costs: a base and so much a unit (Heratio's reproduction pricing; roadmap phase 19c).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'reproduction_type_id', 'base_price', 'unit_price', 'unit'])]
class ReproductionPrice extends Model
{
    use Audited, HasUuids;

    protected $table = 'reproduction_prices';

    protected function casts(): array
    {
        return ['base_price' => 'decimal:2', 'unit_price' => 'decimal:2'];
    }

    public function reproductionType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'reproduction_type_id');
    }
}
