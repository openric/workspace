<?php

// OpenRiC Workspace - a record an export permit covers (roadmap phase 18d, ADR 0016).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['permit_id', 'record_resource_id'])]
class ExportPermitRecord extends Model
{
    use Audited, HasUuids;

    protected $table = 'export_permit_records';

    public $timestamps = false;

    public function permit(): BelongsTo
    {
        return $this->belongsTo(ExportPermit::class);
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }
}
