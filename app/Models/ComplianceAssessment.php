<?php

// OpenRiC Workspace - a records management compliance assessment against a standard (roadmap phase 13c, ADR 0015).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'framework_id', 'reference', 'title', 'scope', 'period_start', 'period_end', 'score_total', 'score_max', 'findings', 'recommendations', 'status_id', 'assessed_by_id', 'assessed_at', 'signed_off_by', 'signed_off_at'])]
class ComplianceAssessment extends Model
{
    use Audited, HasUuids;

    protected $table = 'compliance_assessments';

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'score_total' => 'decimal:2', 'score_max' => 'decimal:2', 'findings' => 'array', 'recommendations' => 'array', 'assessed_at' => 'datetime', 'signed_off_at' => 'datetime'];
    }

    public function framework(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function assessedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by_id');
    }
}
