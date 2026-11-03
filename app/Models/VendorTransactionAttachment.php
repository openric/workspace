<?php

// OpenRiC Workspace - a quote, invoice, condition report or photograph filed with a vendor transaction, kept in the
// file store under its checksum (Heratio's ahg_vendor_transaction_attachments; roadmap phase 22d).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'transaction_id', 'type_id', 'original_name', 'storage_path', 'sha256', 'size_bytes', 'mime_type', 'scanned_with', 'scanned_at', 'description', 'uploaded_by_id', 'created_at'])]
class VendorTransactionAttachment extends Model
{
    use Audited, HasUuids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'created_at' => 'immutable_datetime', 'scanned_at' => 'immutable_datetime'];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(VendorTransaction::class, 'transaction_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'type_id');
    }
}
