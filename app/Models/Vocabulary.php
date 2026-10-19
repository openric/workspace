<?php

// OpenRiC Workspace - a controlled vocabulary.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vocabulary extends Model
{
    use HasUuids;

    protected $fillable = ['iri', 'title', 'source_url', 'institution_may_extend', 'imported_at'];

    protected function casts(): array
    {
        return [
            'institution_may_extend' => 'boolean',
            'imported_at' => 'immutable_datetime',
        ];
    }

    public function concepts(): HasMany
    {
        return $this->hasMany(Concept::class);
    }
}
