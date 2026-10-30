<?php

// OpenRiC Workspace - a document supporting the provenance of a unit of description, or one step of it (Heratio's
// provenance_document): an uploaded file kept by the file store, a link or an archive reference.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'record_resource_id', 'provenance_entry_id', 'document_type_id', 'title', 'description', 'document_date', 'document_date_text',
    'external_url', 'archive_reference', 'is_public', 'original_name', 'storage_path', 'mime_type', 'size_bytes', 'sha256', 'scanned_with', 'scanned_at', 'uploaded_by_id'])]
class ProvenanceDocument extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['document_date' => 'immutable_date', 'is_public' => 'boolean', 'scanned_at' => 'immutable_datetime'];
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'document_type_id');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(ProvenanceEntry::class, 'provenance_entry_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }
}
