<?php

// OpenRiC Workspace - a data subject's consent, given and perhaps withdrawn (Heratio's privacy_consent_record); roadmap phase 18a, ADR 0016.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'data_subject', 'contact', 'purpose', 'processing_activity_id', 'given_at', 'withdrawn_at', 'source', 'note', 'recorded_by_id'])]
class ConsentRecord extends Model
{
    use Audited, HasUuids;

    protected $table = 'consent_records';

    protected function casts(): array
    {
        return ['given_at' => 'immutable_datetime', 'withdrawn_at' => 'immutable_datetime'];
    }

    public function processingActivity(): BelongsTo
    {
        return $this->belongsTo(ProcessingActivity::class, 'processing_activity_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }
}
