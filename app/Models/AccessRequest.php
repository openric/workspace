<?php

// OpenRiC Workspace - a request for the files of an instantiation available on request (roadmap phase 7).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'user_id', 'instantiation_id', 'reason', 'justification', 'urgency_id', 'status_id', 'reviewed_by_id', 'reviewed_at', 'review_notes', 'expires_at'])]
class AccessRequest extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['reviewed_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function instantiation(): BelongsTo
    {
        return $this->belongsTo(Instantiation::class);
    }

    public function urgency(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'urgency_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }

    public function hasStatus(string $notation): bool
    {
        return $this->status->notation === $notation;
    }

    /** Approved, and its access has not ended. */
    public function grantsAccess(): bool
    {
        return $this->hasStatus('approved') && $this->expires_at !== null && $this->expires_at->isFuture();
    }
}
