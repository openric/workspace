<?php

// OpenRiC Workspace - an entity with identifiers as entities (ADR 0006).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Description;

use App\Models\Identifier;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;

trait HasIdentifiers
{
    public function identifiers(): HasMany
    {
        return $this->hasMany(Identifier::class, 'subject_id')->where('subject_table', $this->getTable())->orderBy('created_at');
    }

    /** The local identifier (ISDIAH 5.1.1, ISAD(G) 3.1.1, ISAAR(CPF) 5.1.6), or null. */
    protected function identifier(): Attribute
    {
        return Attribute::get(fn () => $this->identifiers->first(fn (Identifier $i) => $i->identifierType->iri === Vocab::IDENTIFIER_TYPES.'local')?->value);
    }
}
