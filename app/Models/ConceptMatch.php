<?php

// OpenRiC Workspace - a local concept's match to a concept in another vocabulary (skos:exactMatch and the other mapping properties).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['concept_id', 'match_type_id', 'target_iri', 'target_label', 'target_scheme'])]
class ConceptMatch extends Model
{
    use HasUuids;

    public function matchType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'match_type_id');
    }
}
