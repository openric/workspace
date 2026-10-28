<?php

// OpenRiC Workspace - a request for access (roadmap phase 7): to the files of an instantiation available on request, or, as
// Heratio's general request, to everything, a collection or an item.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'user_id', 'instantiation_id', 'reason', 'justification', 'urgency_id', 'status_id', 'reviewed_by_id', 'reviewed_at', 'review_notes', 'expires_at',
    'scope_id', 'record_resource_id', 'request_type_id', 'access_type_id', 'subject'])]
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

    /** The collection or item asked for; none when the request is for everything. */
    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    /** What the request covers: everything, a collection or a single item. */
    public function covers(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'scope_id');
    }

    public function requestType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'request_type_id');
    }

    public function accessType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'access_type_id');
    }

    /** What is asked for, in words: the instantiation, the collection or item, or everything. */
    public function target(): string
    {
        $languages = [app()->getLocale(), 'en'];

        return match (true) {
            $this->instantiation !== null => (string) $this->instantiation->described('instantiation-title', $languages),
            $this->record !== null => (string) $this->record->described('title', $languages),
            default => __('everything available on request'),
        };
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
