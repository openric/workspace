<?php

// OpenRiC Workspace - an appraisal template of the institution (PSIS's and Heratio's accession_appraisal_template): its
// criteria with their weights and descriptions, and the sector it is for.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'name', 'description', 'sector_id', 'criteria', 'is_default'])]
class AppraisalTemplate extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['criteria' => 'array', 'is_default' => 'boolean'];
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'sector_id');
    }
}
