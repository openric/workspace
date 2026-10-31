<?php

// OpenRiC Workspace - a field of a unit of description redacted for the public (Heratio's
// information_object_privacy_field; roadmap phase 18b, ADR 0016): in full, in part by a pattern, or pseudonymised.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'record_resource_id', 'field', 'type_id', 'pattern_id', 'reason', 'legal_basis', 'is_sensitive', 'created_by_id'])]
class RecordRedaction extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['is_sensitive' => 'boolean'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'type_id');
    }

    public function pattern(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'pattern_id');
    }
}
