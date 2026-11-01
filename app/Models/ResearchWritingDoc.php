<?php

// OpenRiC Workspace - a document of a research project's writing studio (roadmap phase 19g, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_id', 'title', 'doc_type_id', 'status_id', 'created_by_id'])]
class ResearchWritingDoc extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_writing_docs';

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }

    public function docType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(ResearchWritingSection::class, 'doc_id')->orderBy('sort_order');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ResearchWritingVersion::class, 'doc_id')->orderByDesc('version_no');
    }
}
