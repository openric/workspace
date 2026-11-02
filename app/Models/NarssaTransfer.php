<?php

// OpenRiC Workspace - a transfer of records to the National Archives and Records Service of South Africa (roadmap phase 18d, ADR 0016).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'reference', 'title', 'description', 'schedule_codes', 'initiated_by_id', 'item_count', 'total_size_bytes', 'package_path', 'package_sha256', 'status_id', 'transmitted_at', 'accepted_at', 'receipt_reference', 'notes'])]
class NarssaTransfer extends Model
{
    use Audited, HasUuids;

    protected $table = 'narssa_transfers';

    protected function casts(): array
    {
        return ['item_count' => 'integer', 'total_size_bytes' => 'integer', 'transmitted_at' => 'datetime', 'accepted_at' => 'datetime'];
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(NarssaTransferItem::class, 'transfer_id');
    }
}
