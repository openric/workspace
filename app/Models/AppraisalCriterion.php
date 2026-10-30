<?php

// OpenRiC Workspace - a scored criterion of an appraisal (PSIS's accession_appraisal_criterion): a score of 1 to 5 and a weight.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['appraisal_id', 'name', 'score', 'weight', 'notes', 'position'])]
class AppraisalCriterion extends Model
{
    use Audited, HasUuids;

    protected $table = 'appraisal_criteria';

    public function appraisal(): BelongsTo
    {
        return $this->belongsTo(AccessionAppraisal::class, 'appraisal_id');
    }
}
