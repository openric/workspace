<?php

// OpenRiC Workspace - a service a vendor offers, at its rates (Heratio's ahg_vendor_services; roadmap phase 22d).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['vendor_id', 'service_type_id', 'hourly_rate', 'fixed_rate', 'currency_id', 'is_preferred', 'notes'])]
class VendorService extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['hourly_rate' => 'decimal:2', 'fixed_rate' => 'decimal:2', 'is_preferred' => 'boolean'];
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'service_type_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'currency_id');
    }
}
