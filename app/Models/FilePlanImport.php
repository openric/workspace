<?php

// OpenRiC Workspace - an import of a file plan (roadmap phase 13c, ADR 0015).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'source_id', 'source_filename', 'department', 'agency_code', 'status_id', 'total_nodes', 'imported_nodes', 'linked_records', 'errors', 'column_mapping', 'imported_by_id', 'created_at', 'completed_at'])]
class FilePlanImport extends Model
{
    use Audited, HasUuids;

    protected $table = 'file_plan_imports';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['errors' => 'array', 'column_mapping' => 'array', 'created_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by_id');
    }
}
