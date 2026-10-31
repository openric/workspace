<?php

// OpenRiC Workspace - a data protection law and its regulator, with the days to answer a request and the hours to report a compromise (Heratio's privacy_jurisdiction); roadmap phase 18a, ADR 0016.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'full_name', 'country', 'regulator', 'regulator_url', 'request_days', 'breach_notice_hours', 'effective_on', 'is_active', 'position'])]
class PrivacyJurisdiction extends Model
{
    use Audited, HasUuids;

    protected $table = 'privacy_jurisdictions';

    protected function casts(): array
    {
        return ['request_days' => 'integer', 'breach_notice_hours' => 'integer', 'effective_on' => 'immutable_date', 'is_active' => 'boolean', 'position' => 'integer'];
    }
}
