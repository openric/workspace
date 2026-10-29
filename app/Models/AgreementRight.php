<?php

// OpenRiC Workspace - a right of a donor agreement (Heratio's donor_agreement_right): what the institution may do with the
// material, to its digital and physical forms and its metadata, when, and on what conditions.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'agreement_id', 'right_id', 'permission_id', 'conditions', 'applies_to_digital', 'applies_to_physical', 'applies_to_metadata',
    'starts_on', 'ends_on', 'requires_donor_approval', 'requires_fee', 'fee_structure', 'note'])]
class AgreementRight extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'ends_on' => 'immutable_date', 'applies_to_digital' => 'boolean', 'applies_to_physical' => 'boolean',
            'applies_to_metadata' => 'boolean', 'requires_donor_approval' => 'boolean', 'requires_fee' => 'boolean'];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    public function right(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'right_id');
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'permission_id');
    }
}
