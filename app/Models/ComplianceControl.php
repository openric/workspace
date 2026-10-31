<?php

// OpenRiC Workspace - a control of the compliance catalogue and the regimes it answers (Heratio's ahg_compliance_control and ahg_compliance_mapping); roadmap phase 18a, ADR 0016.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'category', 'objective', 'recommended_config', 'standards', 'mappings', 'position'])]
class ComplianceControl extends Model
{
    use Audited, HasUuids;

    protected $table = 'compliance_controls';

    protected function casts(): array
    {
        return ['mappings' => 'array', 'position' => 'integer'];
    }
}
