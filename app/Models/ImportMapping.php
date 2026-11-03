<?php

// OpenRiC Workspace - a column mapping kept by name for imports, after PSIS's ingest_mapping (roadmap phase 22a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['institution_id', 'name', 'format', 'columns', 'created_by_id'])]
class ImportMapping extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['columns' => 'array'];
    }
}
