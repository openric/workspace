<?php

// OpenRiC Workspace - each use of a share link, and each refusal (Heratio's information_object_share_access; roadmap phase 19a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['share_link_id', 'event_id', 'ip_address', 'user_agent', 'happened_at'])]
class ShareLinkEvent extends Model
{
    use HasUuids;

    protected $table = 'share_link_events';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['happened_at' => 'immutable_datetime'];
    }

    public function shareLink(): BelongsTo
    {
        return $this->belongsTo(ShareLink::class, 'share_link_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'event_id');
    }
}
