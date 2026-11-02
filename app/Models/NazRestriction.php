<?php

// OpenRiC Workspace - a closure period or protection of a record under the National Archives of Zimbabwe Act, kept as the embargo that enforces it (roadmap phase 18e, ADR 0016).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['record_resource_id', 'embargo_id', 'kind', 'closure_type_id', 'protection_type_id', 'years', 'authority_reference', 'created_by_id'])]
class NazRestriction extends Model
{
    use Audited, HasUuids;

    protected $table = 'naz_restrictions';

    protected function casts(): array
    {
        return ['years' => 'integer'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function embargo(): BelongsTo
    {
        return $this->belongsTo(Embargo::class);
    }

    public function closureType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function protectionType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }
}
