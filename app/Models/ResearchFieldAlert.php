<?php

// OpenRiC Workspace - news of a work a research project cites: a retraction, an update or a related work (roadmap phase 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'watch_id', 'alert_type_id', 'title', 'detail', 'url', 'is_read', 'detected_at'])]
class ResearchFieldAlert extends Model
{
    use HasUuids;

    protected $table = 'research_field_alerts';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['is_read' => 'boolean', 'detected_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }

    public function watch(): BelongsTo
    {
        return $this->belongsTo(ResearchFieldWatch::class);
    }

    public function alertType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }
}
