<?php

// OpenRiC Workspace - a relation type: a bundled one with what it connects, or an institution's own narrowing one
// (App\Relations\RelationTypes; migration 2026_10_29_000100).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['concept_id', 'institution_id', 'base_id'])]
class RelationType extends Model
{
    use Audited;

    protected $primaryKey = 'concept_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /** The bundled types are system data, loaded by the migration; an institution's own are audited. */
    public function isAudited(): bool
    {
        return $this->institution_id !== null;
    }

    public function concept(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'concept_id');
    }

    public function base(): BelongsTo
    {
        return $this->belongsTo(self::class, 'base_id');
    }

    public function phrases(): HasMany
    {
        return $this->hasMany(RelationTypePhrase::class, 'relation_type_id');
    }
}
