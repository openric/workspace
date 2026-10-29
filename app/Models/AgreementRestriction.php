<?php

// OpenRiC Workspace - a restriction of a donor agreement (Heratio's donor_agreement_restriction): on all of the material
// or some, from and until when, how it is released or overridden, why, and under POPIA which personal information.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'agreement_id', 'restriction_type_id', 'applies_to_all', 'specific_materials', 'box_list', 'starts_on', 'ends_on', 'auto_release',
    'release_on', 'release_trigger', 'can_be_overridden', 'override_authority', 'reason', 'legal_basis', 'popia_category_id', 'data_subject_consent',
    'security_clearance_level', 'is_active', 'note'])]
class AgreementRestriction extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'ends_on' => 'immutable_date', 'release_on' => 'immutable_date', 'applies_to_all' => 'boolean',
            'auto_release' => 'boolean', 'can_be_overridden' => 'boolean', 'data_subject_consent' => 'boolean', 'is_active' => 'boolean'];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    public function restrictionType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'restriction_type_id');
    }

    public function popiaCategory(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'popia_category_id');
    }
}
