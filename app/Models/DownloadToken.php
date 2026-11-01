<?php

// OpenRiC Workspace - a time-limited download of a paid digital item (Heratio's ahg_download_token; roadmap phase 19a). Only its hash is kept.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_item_id', 'token_hash', 'expires_at', 'max_downloads', 'download_count', 'last_downloaded_at'])]
class DownloadToken extends Model
{
    use Audited, HasUuids;

    protected $table = 'download_tokens';

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'last_downloaded_at' => 'immutable_datetime', 'max_downloads' => 'integer', 'download_count' => 'integer'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }
}
