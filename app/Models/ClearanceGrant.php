<?php

// OpenRiC Workspace - access to one classified record for one account, for a time (roadmap phase 18c, ADR 0016).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'record_resource_id', 'request_id', 'request_type_id', 'granted_by_id', 'granted_at', 'expires_at', 'revoked_at', 'revoked_by_id'])]
class ClearanceGrant extends Model
{
    use Audited, HasUuids;

    protected $table = 'clearance_grants';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['granted_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function requestType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }
}
