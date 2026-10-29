<?php

// OpenRiC Workspace - a document of a donor agreement (Heratio's donor_agreement_document): the file, kept by its SHA-256
// through FileStore, and what it is, when, which version, whether signed and by whom, and whether it is confidential.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'agreement_id', 'document_type_id', 'original_name', 'storage_path', 'mime_type', 'size_bytes', 'sha256', 'scanned_with',
    'scanned_at', 'title', 'description', 'document_date', 'version', 'is_signed', 'signed_on', 'signed_by', 'is_confidential', 'access_restriction', 'uploaded_by_id'])]
class AgreementDocument extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['document_date' => 'immutable_date', 'signed_on' => 'immutable_date', 'scanned_at' => 'immutable_datetime', 'is_signed' => 'boolean',
            'is_confidential' => 'boolean', 'size_bytes' => 'integer'];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'document_type_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }
}
