<?php

// OpenRiC Workspace - a structure a built report starts from (roadmap phase 8; PSIS ahgReportBuilderPlugin, Heratio ahg-reports report_template).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['institution_id', 'name', 'description', 'structure', 'created_by_id'])]
class ReportTemplate extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['structure' => 'array'];
    }

    /** Bundled with the Workspace (no institution), not the institution's own. */
    public function isBundled(): bool
    {
        return $this->institution_id === null;
    }
}
