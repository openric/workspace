<?php

// OpenRiC Workspace - a proposal for a record, sent through the API, waiting for review (ADR 0007).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'record_resource_id', 'kind_id', 'status_id', 'value', 'element_id', 'language', 'agent_type_id', 'instantiation_id',
    'linked_table', 'linked_id', 'link_reason', 'authority', 'confidence', 'evidence', 'sources', 'origin_label', 'proposed_by_id', 'api_key_id',
    'reviewed_by_id', 'reviewed_at', 'review_note'])]
class Proposal extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['authority' => 'array', 'sources' => 'array', 'confidence' => 'float', 'reviewed_at' => 'immutable_datetime'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function kind(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'kind_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function element(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'element_id');
    }

    public function agentType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'agent_type_id');
    }

    public function instantiation(): BelongsTo
    {
        return $this->belongsTo(Instantiation::class);
    }

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }
}
