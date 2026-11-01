<?php

// OpenRiC Workspace - a researcher's annotation of an entity, or a part of it (roadmap phase 19d, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'user_id', 'subject_table', 'subject_id', 'collection_id', 'instantiation_id', 'annotation_type_id', 'title', 'content', 'canvas_id', 'tags', 'visibility_id', 'project_id'])]
class ResearchAnnotation extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_annotations';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(ResearchCollection::class);
    }

    public function annotationType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function visibility(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(ResearchAnnotationTarget::class, 'annotation_id');
    }

    /** The research project it belongs to (phase 19f). */
    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }
}
