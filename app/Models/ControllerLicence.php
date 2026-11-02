<?php

// OpenRiC Workspace - a data controller's licence, as the Cyber and Data Protection Act requires (roadmap phase 18e, ADR 0016).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'jurisdiction_id', 'reference', 'licence_number', 'tier_id', 'organisation_name', 'registered_on', 'issued_on', 'expires_on', 'regulator_reference', 'data_subjects_count', 'renewal_reminder_sent_at', 'status_id', 'notes', 'created_by_id'])]
class ControllerLicence extends Model
{
    use Audited, HasUuids;

    protected $table = 'controller_licences';

    protected function casts(): array
    {
        return ['registered_on' => 'date', 'issued_on' => 'date', 'expires_on' => 'date', 'renewal_reminder_sent_at' => 'datetime', 'data_subjects_count' => 'integer'];
    }

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(PrivacyJurisdiction::class);
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }
}
