<?php

// OpenRiC Workspace - a template for printing labels, after Heratio's label_template (roadmap phase 22a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'name', 'page_size_id', 'columns', 'rows', 'label_width_mm', 'label_height_mm', 'margin_mm', 'gutter_mm', 'font_size_pt', 'show_title', 'show_identifier', 'show_repository', 'show_barcode', 'barcode_source_id', 'show_qr', 'qr_target_id', 'is_default', 'created_by_id'])]
class LabelTemplate extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['columns' => 'integer', 'rows' => 'integer', 'label_width_mm' => 'float', 'label_height_mm' => 'float', 'margin_mm' => 'float', 'gutter_mm' => 'float', 'font_size_pt' => 'integer', 'show_title' => 'boolean', 'show_identifier' => 'boolean', 'show_repository' => 'boolean', 'show_barcode' => 'boolean', 'show_qr' => 'boolean', 'is_default' => 'boolean'];
    }

    public function pageSize(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'page_size_id');
    }

    public function barcodeSource(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'barcode_source_id');
    }

    public function qrTarget(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'qr_target_id');
    }
}
