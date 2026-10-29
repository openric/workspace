<?php

// OpenRiC Workspace - how a source of an accession is to be handled (CAAIS 2.1.6).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'accession_id', 'agent_id', 'confidentiality_id', 'position'])]
class AccessionSourceConfidentiality extends Model
{
    use Audited, HasUuids;

    protected $table = 'accession_source_confidentiality';

    public function accession(): BelongsTo
    {
        return $this->belongsTo(Accession::class);
    }

    public function confidentiality(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'confidentiality_id');
    }
}
