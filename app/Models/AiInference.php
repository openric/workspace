<?php

// OpenRiC Workspace - one call a model answered through the AI gateway, with its receipt in the chain (Heratio's
// ahg_ai_inference and its inference receipt; roadmap phase 17a). Written only by App\Ai\Inferences, never changed.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiInference extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['confidence' => 'float', 'succeeded' => 'boolean', 'details' => 'array', 'occurred_at' => 'immutable_datetime', 'seq' => 'integer'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'service_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class, 'inference_id');
    }

    /** What it was about, where that is a record, an instantiation or another model of the Workspace. */
    public function subject(): ?Model
    {
        $class = collect(['record_resources' => RecordResource::class, 'instantiations' => Instantiation::class, 'agents' => Agent::class, 'places' => Place::class])
            ->get((string) $this->subject_table);

        return $class && $this->subject_id ? $class::find($this->subject_id) : null;
    }
}
