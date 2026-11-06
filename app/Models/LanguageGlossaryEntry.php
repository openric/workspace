<?php

// OpenRiC Workspace - a term of a language's community glossary, shown once reviewed (Heratio's language_revival_glossary; phase 17d4).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'language', 'term', 'meaning', 'usage_example', 'source', 'contributor_name', 'status_id', 'contributed_by_id', 'moderated_by_id', 'moderated_at'])]
class LanguageGlossaryEntry extends Model
{
    use Audited, HasUuids;

    protected $table = 'language_glossary';

    protected function casts(): array
    {
        return ['moderated_at' => 'immutable_datetime'];
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }
}
