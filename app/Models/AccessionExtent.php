<?php

// OpenRiC Workspace - an extent statement of an accession (CAAIS 3.2).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'accession_id', 'extent_type_id', 'quantity', 'is_estimate', 'unit_id', 'content_type_id', 'carrier_type_id', 'digital_file_formats', 'note', 'position'])]
class AccessionExtent extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['is_estimate' => 'boolean', 'quantity' => 'decimal:3'];
    }

    public function accession(): BelongsTo
    {
        return $this->belongsTo(Accession::class);
    }

    public function extentType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'extent_type_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'unit_id');
    }

    public function contentType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'content_type_id');
    }

    public function carrierType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'carrier_type_id');
    }
}
