<?php

// OpenRiC Workspace - a hotspot's title and description in one language, with its state (roadmap phase 21, 3D objects; Heratio's object_3d_hotspot_i18n).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['hotspot_id', 'language', 'title', 'description', 'state_id'])]
class ModelHotspotText extends Model
{
    use Audited, HasUuids;

    public function hotspot(): BelongsTo
    {
        return $this->belongsTo(ModelHotspot::class, 'hotspot_id');
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'state_id');
    }
}
