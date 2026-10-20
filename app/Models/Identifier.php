<?php

// OpenRiC Workspace - an identifier as an entity (rico:Identifier; ADR 0006).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'iri', 'subject_table', 'subject_id', 'identifier_type_id', 'value'])]
class Identifier extends Model
{
    use Audited, HasUuids;

    /** The URL path segment of its persistent IRI: {base IRI}/identifier/{UUID}. */
    public const IRI_SEGMENT = 'identifier';

    public function identifierType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'identifier_type_id');
    }
}
