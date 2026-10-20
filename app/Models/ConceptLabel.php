<?php

// OpenRiC Workspace - a concept's label, definition and scope note in one language.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConceptLabel extends Model
{
    protected $primaryKey = null;

    public $incrementing = false;

    protected $fillable = ['concept_id', 'language', 'pref_label', 'definition', 'scope_note', 'history_note', 'source_note', 'display_note'];

    public function concept(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }
}
