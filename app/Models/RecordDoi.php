<?php

// OpenRiC Workspace - a unit of description's DOI and its state at DataCite (Heratio's ahg_doi; roadmap phase 20c).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'record_resource_id', 'doi', 'state_id', 'test', 'metadata', 'datacite_response', 'minted_by_id', 'minted_at', 'last_sync_at'])]
class RecordDoi extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['test' => 'boolean', 'metadata' => 'array', 'datacite_response' => 'array', 'minted_at' => 'immutable_datetime', 'last_sync_at' => 'immutable_datetime'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'state_id');
    }

    public function url(): string
    {
        return 'https://doi.org/'.$this->doi;
    }
}
