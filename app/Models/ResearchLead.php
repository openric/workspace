<?php

// OpenRiC Workspace - a research lead promoted from a discovery, shown in the public catalogue only once a curator publishes it (Heratio's research_lead; phase 17d4).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'record_resource_id', 'discovery_id', 'headline', 'lead_text', 'why_it_matters', 'connection_count', 'confidence', 'evidence', 'status_id', 'ai_labelled', 'inference_id', 'curated_by_id', 'generated_at', 'published_at'])]
class ResearchLead extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_leads';

    protected function casts(): array
    {
        return ['evidence' => 'array', 'ai_labelled' => 'boolean', 'generated_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function discovery(): BelongsTo
    {
        return $this->belongsTo(ScholarshipDiscovery::class, 'discovery_id');
    }
}
