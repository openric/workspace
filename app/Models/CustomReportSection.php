<?php

// OpenRiC Workspace - one section of a built report (roadmap phase 8; PSIS ahgReportBuilderPlugin, Heratio ahg-reports report_section).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['report_id', 'type_id', 'title', 'content', 'config', 'position'])]
class CustomReportSection extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['config' => 'array'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(CustomReport::class, 'report_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'type_id');
    }
}
