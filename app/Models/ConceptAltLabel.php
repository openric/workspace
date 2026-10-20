<?php

// OpenRiC Workspace - another label of a local concept (skos:altLabel, or skos:hiddenLabel; AtoM's "use for").
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['concept_id', 'language', 'label', 'is_hidden'])]
class ConceptAltLabel extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['is_hidden' => 'boolean'];
    }
}
