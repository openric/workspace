<?php

// OpenRiC Workspace - the watermark of one description, over the institution's (Heratio's object_watermark_setting).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'record_resource_id', 'enabled', 'watermark_type_id', 'watermark_position_id', 'opacity'])]
class RecordWatermark extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'opacity' => 'integer'];
    }

    public function watermarkType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'watermark_type_id');
    }

    public function watermarkPosition(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'watermark_position_id');
    }
}
