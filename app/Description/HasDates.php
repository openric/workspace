<?php

// OpenRiC Workspace - an entity with dates as entities (ADR 0006).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Description;

use App\Models\RicDate;
use Illuminate\Database\Eloquent\Relations\HasMany;

trait HasDates
{
    public function dates(): HasMany
    {
        return $this->hasMany(RicDate::class, 'subject_id')->where('subject_table', $this->getTable())
            ->orderByRaw('earliest NULLS FIRST')->orderBy('created_at');
    }
}
