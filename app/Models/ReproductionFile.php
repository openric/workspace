<?php

// OpenRiC Workspace - a reproduction made, delivered to the researcher (Heratio's research_reproduction_file; roadmap phase 19c). Only the download token's hash is kept.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['reproduction_item_id', 'research_file_id', 'download_token_hash', 'download_expires_at', 'download_count'])]
class ReproductionFile extends Model
{
    use Audited, HasUuids;

    protected $table = 'reproduction_files';

    protected function casts(): array
    {
        return ['download_expires_at' => 'immutable_datetime', 'download_count' => 'integer'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ReproductionItem::class, 'reproduction_item_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(ResearchFile::class, 'research_file_id');
    }
}
