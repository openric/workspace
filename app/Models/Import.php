<?php

// OpenRiC Workspace - one import of archival description: its file, choices and report (WS-015).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'user_id', 'format', 'source', 'file_name', 'file_sha256', 'content', 'language', 'repository_id', 'parent_id', 'options', 'report', 'committed_at'])]
class Import extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['options' => 'array', 'report' => 'array', 'committed_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'parent_id');
    }
}
