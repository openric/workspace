<?php

// OpenRiC Workspace - one block of a landing page, of its type and with its settings, perhaps in a column of a row block
// (Heratio's atom_landing_page_block; roadmap phase 20a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['landing_page_id', 'block_type_id', 'parent_block_id', 'column_slot', 'title', 'position', 'config', 'css_classes', 'full_width', 'background_color', 'text_color',
    'padding_top', 'padding_bottom', 'col_span', 'is_visible'])]
class LandingPageBlock extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['config' => 'array', 'full_width' => 'boolean', 'is_visible' => 'boolean', 'padding_top' => 'float', 'padding_bottom' => 'float'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(LandingPage::class, 'landing_page_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'block_type_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_block_id')->orderBy('column_slot')->orderBy('position');
    }
}
