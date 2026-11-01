<?php

// OpenRiC Workspace - where an annotation points into its entity, as a W3C Web Annotation selector (roadmap phase 19d, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['annotation_id', 'selector_type_id', 'selector', 'source_url', 'created_at'])]
class ResearchAnnotationTarget extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_annotation_targets';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['selector' => 'array', 'created_at' => 'datetime'];
    }

    public function annotation(): BelongsTo
    {
        return $this->belongsTo(ResearchAnnotation::class);
    }

    public function selectorType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }
}
