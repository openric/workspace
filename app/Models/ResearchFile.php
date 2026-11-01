<?php

// OpenRiC Workspace - a file of research services: a researcher's photo, a verification document, a floor plan, an attachment (roadmap phase 19, ADR 0017). Kept by checksum in the file store.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['institution_id', 'original_name', 'storage_path', 'mime_type', 'size_bytes', 'sha256', 'scanned_with', 'scanned_at', 'uploaded_by_id'])]
class ResearchFile extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_files';

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'scanned_at' => 'immutable_datetime'];
    }
}
