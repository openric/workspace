<?php

// OpenRiC Workspace - a flag a federation peer pushed, kept for a curator to accept or decline before it is shown
// (Heratio's endangered_inbound_flag; phase 17d3).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'peer_id', 'source_peer_base_url', 'source_peer_name', 'reference', 'dedupe_key', 'title', 'risk_id', 'urgency_id', 'capture_status_id',
    'reason', 'catalogue_url', 'payload', 'peer_verified', 'key_fingerprint', 'review_status_id', 'reviewed_by_id', 'reviewed_at', 'received_at'])]
class EndangeredInboundFlag extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['payload' => 'array', 'peer_verified' => 'boolean', 'reviewed_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime'];
    }

    public function peer(): BelongsTo
    {
        return $this->belongsTo(FederationPeer::class, 'peer_id');
    }

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'risk_id');
    }

    public function urgency(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'urgency_id');
    }

    public function captureStatus(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'capture_status_id');
    }

    public function reviewStatus(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'review_status_id');
    }
}
