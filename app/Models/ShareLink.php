<?php

// OpenRiC Workspace - a time-limited link that opens one record to someone outside the institution (Heratio's information_object_share_token; roadmap phase 19a). Only the token's hash is kept.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'record_resource_id', 'token_hash', 'issued_by_id', 'recipient_email', 'recipient_note', 'expires_at', 'max_access', 'access_count', 'allows_download', 'revoked_at', 'revoked_by_id', 'revoke_reason'])]
class ShareLink extends Model
{
    use Audited, HasUuids;

    protected $table = 'share_links';

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime', 'max_access' => 'integer', 'access_count' => 'integer', 'allows_download' => 'boolean'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_id');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShareLinkEvent::class, 'share_link_id');
    }
}
