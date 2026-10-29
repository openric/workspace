<?php

// OpenRiC Workspace - a deaccession of an accession (AtoM's deaccession): identifier, scope, date, description, extent and reason.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'accession_id', 'identifier', 'scope_id', 'date', 'description', 'extent', 'reason', 'language', 'position'])]
class Deaccession extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function accession(): BelongsTo
    {
        return $this->belongsTo(Accession::class);
    }

    public function scope(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'scope_id');
    }
}
