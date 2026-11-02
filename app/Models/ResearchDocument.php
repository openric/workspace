<?php

// OpenRiC Workspace - a research document filled in from a template (roadmap phase 19j, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['template_id', 'user_id', 'project_id', 'title', 'values', 'created_by_id'])]
class ResearchDocument extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_documents';

    protected function casts(): array
    {
        return ['values' => 'array'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ResearchDocumentTemplate::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }
}
