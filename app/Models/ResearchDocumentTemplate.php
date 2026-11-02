<?php

// OpenRiC Workspace - a template of a research document (roadmap phase 19j, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'name', 'document_type_id', 'description', 'fields', 'created_by_id'])]
class ResearchDocumentTemplate extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_document_templates';

    protected function casts(): array
    {
        return ['fields' => 'array'];
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }
}
