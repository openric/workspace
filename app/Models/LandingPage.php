<?php

// OpenRiC Workspace - a page built of blocks: one of the institution's public landing pages, its default the catalogue's
// home, or a user's own dashboard (Heratio's atom_landing_page; roadmap phase 20a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'owner_id', 'name', 'slug', 'description', 'is_default', 'is_active', 'meta_title', 'meta_description', 'css_classes', 'custom_css', 'published_at', 'created_by_id'])]
class LandingPage extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean', 'published_at' => 'immutable_datetime'];
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(LandingPageBlock::class);
    }
}
