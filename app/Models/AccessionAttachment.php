<?php

// OpenRiC Workspace - a file attached to an accession (PSIS's and Heratio's accession_attachment): the file, kept by its
// SHA-256 through FileStore, its category and description.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'accession_id', 'category_id', 'original_name', 'storage_path', 'mime_type', 'size_bytes', 'sha256', 'scanned_with',
    'scanned_at', 'description', 'uploaded_by_id'])]
class AccessionAttachment extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['scanned_at' => 'immutable_datetime', 'size_bytes' => 'integer'];
    }

    public function accession(): BelongsTo
    {
        return $this->belongsTo(Accession::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'category_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }
}
