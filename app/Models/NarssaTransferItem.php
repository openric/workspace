<?php

// OpenRiC Workspace - a record in a transfer to the National Archives (roadmap phase 18d, ADR 0016).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['transfer_id', 'record_resource_id', 'disposal_action_id', 'reference_code', 'title_snapshot', 'schedule_code', 'file_count', 'file_bytes', 'sha256', 'created_at'])]
class NarssaTransferItem extends Model
{
    use Audited, HasUuids;

    protected $table = 'narssa_transfer_items';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['file_count' => 'integer', 'file_bytes' => 'integer', 'created_at' => 'datetime'];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(NarssaTransfer::class);
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function disposal(): BelongsTo
    {
        return $this->belongsTo(DisposalAction::class, 'disposal_action_id');
    }
}
