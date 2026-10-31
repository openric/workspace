<?php

// OpenRiC Workspace - an IIIF viewer layout an account saved (Heratio's ahg_iiif_workspace; roadmap phase 14b,
// ADR 0014): its windows, each a manifest of this Workspace and the canvas it shows, to open again.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'name', 'windows', 'is_default'])]
class IiifViewerWorkspace extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['windows' => 'array', 'is_default' => 'boolean'];
    }
}
