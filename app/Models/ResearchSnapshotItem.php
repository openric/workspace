<?php

// OpenRiC Workspace - a record as a snapshot took it (roadmap phase 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['snapshot_id', 'record_id', 'record_iri', 'title', 'reference_code', 'language', 'metadata_version', 'rights_snapshot', 'sort_order'])]
class ResearchSnapshotItem extends Model
{
    use HasUuids;

    protected $table = 'research_snapshot_items';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['metadata_version' => 'array', 'rights_snapshot' => 'array'];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(ResearchSnapshot::class);
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class);
    }
}
