<?php

// OpenRiC Workspace - what a service needs at the institution: insurance, a valuation, how long it usually takes, and
// whether it is offered (Heratio's ahg_vendor_service_types; roadmap phase 22d).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['institution_id', 'service_type_id', 'requires_insurance', 'requires_valuation', 'typical_duration_days', 'is_active'])]
class VendorServiceSetting extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['requires_insurance' => 'boolean', 'requires_valuation' => 'boolean', 'typical_duration_days' => 'integer', 'is_active' => 'boolean'];
    }
}
