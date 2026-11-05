<?php

// OpenRiC Workspace - a link to a repatriation claim's shared record, for a claimant's representative without an
// account; only its token's hash is kept (Heratio's repatriation_claim_access; phase 17d2).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['claim_id', 'token_hash', 'grantee_name', 'grantee_role_id', 'can_message', 'expires_at', 'revoked_at', 'last_seen_at', 'created_by_id'])]
class RepatriationShare extends Model
{
    use Audited, HasUuids;

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['can_message' => 'boolean', 'expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime'];
    }

    /** Opening the shared record is not a change worth an audit row. */
    public function notAudited(): array
    {
        return ['last_seen_at'];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(RepatriationClaim::class, 'claim_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'grantee_role_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
