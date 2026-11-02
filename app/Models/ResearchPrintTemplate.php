<?php

// OpenRiC Workspace - a template for printing call slips, paging slips, receipts, badges and labels (roadmap phase 19j, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'name', 'code', 'template_type_id', 'description', 'body', 'page_size', 'orientation', 'margin_mm', 'copies_default', 'is_default', 'is_active', 'created_by_id'])]
class ResearchPrintTemplate extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_print_templates';

    protected function casts(): array
    {
        return ['margin_mm' => 'integer', 'copies_default' => 'integer', 'is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    public function templateType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }
}
