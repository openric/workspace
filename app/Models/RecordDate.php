<?php

// OpenRiC Workspace - one structured date of a unit of description (ISAD(G) 3.1.3).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'record_resource_id', 'date_type_id', 'begin_date', 'end_date'])]
class RecordDate extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['begin_date' => 'immutable_date', 'end_date' => 'immutable_date'];
    }

    public function dateType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'date_type_id');
    }
}
