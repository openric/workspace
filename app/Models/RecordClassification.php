<?php

// OpenRiC Workspace - a record's security classification (roadmap phase 18c, ADR 0016).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['record_resource_id', 'level_id', 'classified_by_id', 'classified_at', 'review_on', 'declassify_on', 'declassify_to_level_id', 'reason', 'handling_instructions', 'inherit_to_children', 'justification'])]
class RecordClassification extends Model
{
    use Audited, HasUuids;

    protected $table = 'record_classifications';

    protected function casts(): array
    {
        return ['classified_at' => 'datetime', 'review_on' => 'date', 'declassify_on' => 'date', 'inherit_to_children' => 'boolean'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(SecurityLevel::class, 'level_id');
    }

    public function declassifyTo(): BelongsTo
    {
        return $this->belongsTo(SecurityLevel::class, 'declassify_to_level_id');
    }

    public function classifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'classified_by_id');
    }
}
