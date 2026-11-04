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

#[Fillable(['institution_id', 'name', 'code', 'template_type_id', 'description', 'body', 'page_size_id', 'orientation_id', 'margin_mm', 'copies_default', 'is_default', 'is_active', 'created_by_id'])]
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

    /** The paper it is set for, from the print page size vocabulary (phase 20d). */
    public function pageSize(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'page_size_id');
    }

    public function pageOrientation(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'orientation_id');
    }

    /** The CSS @page size: the paper in millimetres, the wider side across for landscape. */
    public function cssPageSize(): string
    {
        [$w, $h] = ['a4' => [210, 297], 'a5' => [148, 210], 'a6' => [105, 148], 'letter' => [215.9, 279.4]][$this->pageSize?->notation] ?? [210, 297];

        return $this->pageOrientation?->notation === 'landscape' ? "{$h}mm {$w}mm" : "{$w}mm {$h}mm";
    }
}
