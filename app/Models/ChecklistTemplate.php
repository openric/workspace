<?php

// OpenRiC Workspace - a checklist template of the institution (PSIS's and Heratio's accession_intake_template): its name,
// description and items in order.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['institution_id', 'name', 'description', 'items'])]
class ChecklistTemplate extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['items' => 'array'];
    }
}
