<?php

// OpenRiC Workspace - an institution, the owner of every other record.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'base_iri'])]
class Institution extends Model
{
    use Audited, HasUuids;

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
