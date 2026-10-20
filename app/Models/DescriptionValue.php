<?php

// OpenRiC Workspace - one language value of one element of a description (logical model, section 5).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'subject_table', 'subject_id', 'element_id', 'language', 'value', 'is_original', 'state_id'])]
class DescriptionValue extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['is_original' => 'boolean'];
    }

    public function element(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'element_id');
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'state_id');
    }
}
