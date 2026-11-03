<?php

// OpenRiC Workspace - a file a watched folder or the scan API took in, and what became of it (Heratio's scan inbox) (roadmap phase 22a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'watch_folder_id', 'api_key_id', 'file_name', 'sha256', 'size_bytes', 'outcome_id', 'import_id', 'record_resource_id', 'message', 'created_at'])]
class IntakeFile extends Model
{
    use Audited, HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(WatchFolder::class, 'watch_folder_id');
    }

    public function outcome(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'outcome_id');
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }
}
