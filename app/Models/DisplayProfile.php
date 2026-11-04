<?php

// OpenRiC Workspace - how the public sees a unit of description of a level, or of any level (Heratio's display_profile;
// roadmap phase 20d).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['institution_id', 'name', 'level_id', 'elements', 'labels', 'show_tree', 'thumbnail_size_id', 'updated_by_id'])]
class DisplayProfile extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['elements' => 'array', 'labels' => 'array', 'show_tree' => 'boolean'];
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'level_id');
    }

    public function thumbnailSize(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'thumbnail_size_id');
    }

    public function actions(): BelongsToMany
    {
        return $this->belongsToMany(Concept::class, 'display_profile_actions', 'display_profile_id', 'action_id');
    }
}
