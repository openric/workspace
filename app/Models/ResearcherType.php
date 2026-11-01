<?php

// OpenRiC Workspace - a kind of researcher and what it may do (Heratio's research_researcher_type; roadmap phase 19b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'name', 'code', 'description', 'max_booking_days_advance', 'max_booking_hours_per_day', 'max_materials_per_booking', 'can_remote_access', 'can_request_reproductions', 'can_export_data', 'requires_id_verification', 'auto_approve', 'expiry_months', 'priority_level', 'is_active', 'position'])]
class ResearcherType extends Model
{
    use Audited, HasUuids;

    protected $table = 'researcher_types';

    protected function casts(): array
    {
        return ['max_booking_days_advance' => 'integer', 'max_booking_hours_per_day' => 'integer', 'max_materials_per_booking' => 'integer', 'can_remote_access' => 'boolean', 'can_request_reproductions' => 'boolean', 'can_export_data' => 'boolean', 'requires_id_verification' => 'boolean', 'auto_approve' => 'boolean', 'expiry_months' => 'integer', 'priority_level' => 'integer', 'is_active' => 'boolean'];
    }

    public function researchers(): HasMany
    {
        return $this->hasMany(Researcher::class);
    }
}
