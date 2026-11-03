<?php

// OpenRiC Workspace - a caption track of a sound or video instantiation, WebVTT, after Heratio's caption_track (roadmap phase 22c).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'instantiation_id', 'language', 'kind', 'label', 'storage_path', 'sha256', 'cue_count', 'is_default', 'created_by_id'])]
class CaptionTrack extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['cue_count' => 'integer', 'is_default' => 'boolean'];
    }

    public function instantiation(): BelongsTo
    {
        return $this->belongsTo(Instantiation::class, 'instantiation_id');
    }
}
