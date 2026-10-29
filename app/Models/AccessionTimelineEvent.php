<?php

// OpenRiC Workspace - an event in an accession's chain of custody (PSIS's and Heratio's accession_timeline): what
// happened, who did it, when, and what it said.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'accession_id', 'event_type_id', 'actor_id', 'description', 'metadata', 'occurred_at'])]
class AccessionTimelineEvent extends Model
{
    use HasUuids;

    protected $table = 'accession_timeline';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['metadata' => 'array', 'occurred_at' => 'immutable_datetime'];
    }

    public function accession(): BelongsTo
    {
        return $this->belongsTo(Accession::class);
    }

    public function eventType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'event_type_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
