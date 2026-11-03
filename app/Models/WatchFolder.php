<?php

// OpenRiC Workspace - a folder that takes in what is dropped in it, an FTP or SFTP drop among them, after PSIS's ingest_watch_folder (roadmap phase 22a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'label', 'path', 'format', 'language', 'repository_id', 'parent_id', 'import_mapping_id', 'level_id', 'auto_commit', 'user_id', 'is_enabled', 'last_scan_at', 'last_status', 'files_taken', 'created_by_id'])]
class WatchFolder extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['auto_commit' => 'boolean', 'is_enabled' => 'boolean', 'last_scan_at' => 'immutable_datetime', 'files_taken' => 'integer'];
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'parent_id');
    }

    public function mapping(): BelongsTo
    {
        return $this->belongsTo(ImportMapping::class, 'import_mapping_id');
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'level_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
