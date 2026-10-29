<?php

// OpenRiC Workspace - a preservation requirement of an accession (CAAIS 4.3).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'accession_id', 'requirement_type_id', 'requirement', 'note', 'position'])]
class AccessionPreservationRequirement extends Model
{
    use Audited, HasUuids;

    protected $table = 'accession_preservation_requirements';

    public function accession(): BelongsTo
    {
        return $this->belongsTo(Accession::class);
    }

    public function requirementType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'requirement_type_id');
    }
}
