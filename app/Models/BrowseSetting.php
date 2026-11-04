<?php

// OpenRiC Workspace - how the staff browse lists units of description, for the institution or for one account (Heratio's
// display_mode_global and user_display_preference; roadmap phase 20d).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'user_id', 'view_id', 'sort_id', 'direction_id', 'per_page', 'show_thumbnails', 'allow_user_override'])]
class BrowseSetting extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['per_page' => 'integer', 'show_thumbnails' => 'boolean', 'allow_user_override' => 'boolean'];
    }

    public function view(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'view_id');
    }

    public function sort(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'sort_id');
    }

    public function direction(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'direction_id');
    }
}
