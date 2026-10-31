<?php

// OpenRiC Workspace - the certificate of a record's destruction, keeping what was destroyed after it is gone, after Heratio's destruction_certificate (roadmap phase 13b, ADR 0015).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'number', 'record_resource_id', 'record_iri', 'record_title', 'reference_code', 'authority', 'disposal_class', 'method', 'witness', 'files', 'content_hash', 'approved_by', 'executed_by', 'destroyed_at'])]
class DestructionCertificate extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['files' => 'array', 'destroyed_at' => 'immutable_datetime'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }
}
