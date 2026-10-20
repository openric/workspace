<?php

// OpenRiC Workspace - a controlled vocabulary.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Bundled and published vocabularies have no institution. An institution's own
// vocabulary has one, a kind (concept-scheme-kind vocabulary) and a language
// for its title and description.

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vocabulary extends Model
{
    use Audited, HasUuids;

    /** The URL path segment of a local vocabulary's persistent IRI: {base IRI}/vocabulary/{UUID}. */
    public const IRI_SEGMENT = 'vocabulary';

    protected $fillable = ['iri', 'title', 'source_url', 'institution_may_extend', 'imported_at', 'institution_id', 'kind_id', 'description', 'language'];

    public function isAudited(): bool
    {
        return $this->institution_id !== null;
    }

    protected function casts(): array
    {
        return [
            'institution_may_extend' => 'boolean',
            'imported_at' => 'immutable_datetime',
        ];
    }

    public function kind(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'kind_id');
    }

    public function concepts(): HasMany
    {
        return $this->hasMany(Concept::class);
    }
}
