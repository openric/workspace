<?php

// OpenRiC Workspace - a unit of description in a record set other than its parent: a rico:Proxy (roadmap phase 24).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'iri', 'record_resource_id', 'record_set_id', 'position', 'note', 'created_by_id', 'deleted_at'])]
class RecordMembership extends Model
{
    use Audited, HasUuids;

    /** The URL path segment of its persistent IRI: {base IRI}/proxy/{UUID}. */
    public const IRI_SEGMENT = 'proxy';

    protected function casts(): array
    {
        return ['position' => 'integer', 'deleted_at' => 'immutable_datetime'];
    }

    /** rico:proxyFor: the unit it stands for. */
    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    /** rico:proxyIn: the record set it stands in. */
    public function recordSet(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_set_id');
    }
}
