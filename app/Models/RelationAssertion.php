<?php

// OpenRiC Workspace - an assertion that holds a relation (ADR 0006, WS-006).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'iri', 'relation_id', 'origin_id', 'user_id', 'origin_label', 'basis', 'basis_language', 'asserted_at',
    'status_id', 'reviewed_by_id', 'reviewed_at', 'supersedes_id'])]
class RelationAssertion extends Model
{
    use Audited, HasUuids;

    /** The URL path segment of its persistent IRI: {base IRI}/assertion/{UUID}. */
    public const IRI_SEGMENT = 'assertion';

    protected function casts(): array
    {
        return ['asserted_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime'];
    }

    public function relation(): BelongsTo
    {
        return $this->belongsTo(Relation::class);
    }

    public function origin(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'origin_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }

    /** Who asserted it, for people. */
    public function asserter(): string
    {
        return $this->user?->name ?? (string) $this->origin_label;
    }
}
