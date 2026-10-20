<?php

// OpenRiC Workspace - a unit of description that proves a relation (rico:evidences; ADR 0006).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'relation_id', 'record_resource_id'])]
class RelationEvidence extends Model
{
    use Audited, HasUuids;

    protected $table = 'relation_evidence';

    public function relation(): BelongsTo
    {
        return $this->belongsTo(Relation::class);
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }
}
