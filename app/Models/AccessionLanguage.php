<?php

// OpenRiC Workspace - a language of an accession's material (CAAIS 3.4): an ISO 639 code and a statement.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'accession_id', 'language', 'note', 'position'])]
class AccessionLanguage extends Model
{
    use Audited, HasUuids;

    public function accession(): BelongsTo
    {
        return $this->belongsTo(Accession::class);
    }
}
