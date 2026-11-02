<?php

// OpenRiC Workspace - an Information Officer or deputy (POPIA s.55; Heratio's privacy_officer); roadmap phase 18a, ADR 0016.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'user_id', 'name', 'title', 'email', 'phone', 'jurisdiction_id', 'registration_number', 'is_deputy', 'appointed_on', 'is_active', 'qualifications', 'certificate_number', 'term_ends_on', 'form_dp2_on', 'form_dp2_reference'])]
class PrivacyOfficer extends Model
{
    use Audited, HasUuids;

    protected $table = 'privacy_officers';

    protected function casts(): array
    {
        return ['is_deputy' => 'boolean', 'appointed_on' => 'immutable_date', 'is_active' => 'boolean', 'term_ends_on' => 'immutable_date', 'form_dp2_on' => 'immutable_date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(PrivacyJurisdiction::class, 'jurisdiction_id');
    }
}
