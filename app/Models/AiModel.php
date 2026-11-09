<?php

// OpenRiC Workspace - a model seen in the log of inferences, and what is known of it (roadmap phase 17f; Heratio's ai_model_registry).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'service_id', 'model', 'model_version', 'first_seen_at', 'last_seen_at', 'calls', 'retired_at', 'training_data_summary', 'known_limits', 'intended_purpose'])]
class AiModel extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['first_seen_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime', 'retired_at' => 'immutable_datetime', 'calls' => 'integer'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'service_id');
    }
}
