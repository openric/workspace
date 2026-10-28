<?php

// OpenRiC Workspace - a link that opens a built report without signing in, until it expires (roadmap phase 8; PSIS ahgReportBuilderPlugin ShareService, Heratio ahg-reports report_share).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['report_id', 'token', 'expires_at', 'revoked_at', 'opened', 'created_by_id'])]
class ReportShare extends Model
{
    use Audited, HasUuids;

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    /** Opening it counts; that alone is not worth an audit row. */
    public function notAudited(): array
    {
        return ['opened'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(CustomReport::class, 'report_id');
    }

    public function isOpen(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
