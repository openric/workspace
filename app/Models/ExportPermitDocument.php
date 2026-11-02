<?php

// OpenRiC Workspace - a document of an export permit (roadmap phase 18d, ADR 0016).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['permit_id', 'original_name', 'storage_path', 'mime_type', 'size_bytes', 'sha256', 'kind', 'uploaded_by_id', 'created_at'])]
class ExportPermitDocument extends Model
{
    use Audited, HasUuids;

    protected $table = 'export_permit_documents';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'created_at' => 'datetime'];
    }

    public function permit(): BelongsTo
    {
        return $this->belongsTo(ExportPermit::class);
    }
}
