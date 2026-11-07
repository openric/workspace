<?php

// OpenRiC Workspace - a link to an offline catalogue for someone without an account (Heratio's
// portable_export_share_token; roadmap phase 11c). Only the SHA-256 of its token is kept.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['offline_catalogue_id', 'token_hash', 'label', 'max_downloads', 'download_count', 'expires_at', 'revoked_at', 'last_downloaded_at', 'created_by_id'])]
class OfflineCatalogueShare extends Model
{
    use Audited, HasUuids;

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime', 'last_downloaded_at' => 'immutable_datetime', 'max_downloads' => 'integer', 'download_count' => 'integer'];
    }

    /** A download is not a change worth an audit row. */
    public function notAudited(): array
    {
        return ['download_count', 'last_downloaded_at'];
    }

    public function catalogue(): BelongsTo
    {
        return $this->belongsTo(OfflineCatalogue::class, 'offline_catalogue_id');
    }

    /** Whether the link still opens: not revoked, not past its date, not used up. */
    public function open(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture())
            && ($this->max_downloads === null || $this->download_count < $this->max_downloads);
    }
}
