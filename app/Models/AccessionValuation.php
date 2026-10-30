<?php

// OpenRiC Workspace - a valuation of an accession, for GRAP 103 and IPSAS 45 (PSIS's accession_valuation_history): its
// type, amount, date, valuer, method and reference document.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'accession_id', 'type_id', 'monetary_value', 'currency', 'valued_on', 'valuer', 'method_id', 'reference_document', 'notes', 'recorded_by_id'])]
class AccessionValuation extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['valued_on' => 'immutable_date'];
    }

    public function accession(): BelongsTo
    {
        return $this->belongsTo(Accession::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'type_id');
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'method_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }
}
