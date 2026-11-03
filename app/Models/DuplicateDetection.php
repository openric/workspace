<?php

// OpenRiC Workspace - two archival descriptions that may be the same, and what was decided, after Heratio's ahg_duplicate_detection (roadmap phase 22b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'record_a_id', 'record_b_id', 'similarity_score', 'rule_type_id', 'details', 'status_id', 'reviewed_by_id', 'reviewed_at', 'review_notes', 'auto_detected', 'kept_id', 'moved', 'detected_at'])]
class DuplicateDetection extends Model
{
    use Audited, HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['similarity_score' => 'float', 'details' => 'array', 'moved' => 'array', 'reviewed_at' => 'immutable_datetime', 'detected_at' => 'immutable_datetime', 'auto_detected' => 'boolean'];
    }

    public function recordA(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_a_id');
    }

    public function recordB(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_b_id');
    }

    public function ruleType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'rule_type_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }

    public function kept(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'kept_id');
    }
}
