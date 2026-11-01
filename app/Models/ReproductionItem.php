<?php

// OpenRiC Workspace - one copy asked for in a reproduction request (Heratio's research_reproduction_item; roadmap phase 19c).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['reproduction_request_id', 'record_resource_id', 'digital_file_id', 'reproduction_type_id', 'format', 'resolution', 'colour_mode_id', 'quantity', 'page_range', 'special_instructions', 'unit_price', 'total_price', 'status_id', 'completed_at', 'notes'])]
class ReproductionItem extends Model
{
    use Audited, HasUuids;

    protected $table = 'reproduction_items';

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price' => 'decimal:2', 'total_price' => 'decimal:2', 'completed_at' => 'immutable_datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ReproductionRequest::class, 'reproduction_request_id');
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function digitalFile(): BelongsTo
    {
        return $this->belongsTo(DigitalFile::class, 'digital_file_id');
    }

    public function reproductionType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'reproduction_type_id');
    }

    public function colourMode(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'colour_mode_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(ReproductionFile::class);
    }
}
