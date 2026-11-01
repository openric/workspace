<?php

// OpenRiC Workspace - a researcher's bibliography (Heratio's research_bibliography; roadmap phase 19c). Shared by a link kept as a hash.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'user_id', 'name', 'description', 'citation_style_id', 'is_public', 'share_token_hash', 'project_id'])]
class Bibliography extends Model
{
    use Audited, HasUuids;

    protected $table = 'bibliographies';

    protected function casts(): array
    {
        return ['is_public' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function citationStyle(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'citation_style_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(BibliographyEntry::class)->orderBy('position')->orderBy('created_at');
    }

    /** The research project it belongs to (phase 19f). */
    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }
}
