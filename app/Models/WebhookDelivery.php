<?php

// OpenRiC Workspace - one delivery of a webhook and its attempts, after Heratio's ahg_webhook_delivery (roadmap phase 22c).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['webhook_id', 'event_id', 'entity_kind', 'entity_id', 'payload', 'response_code', 'response_body', 'attempt_count', 'status_id', 'next_retry_at', 'created_at', 'delivered_at'])]
class WebhookDelivery extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['payload' => 'array', 'response_code' => 'integer', 'attempt_count' => 'integer', 'next_retry_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime'];
    }

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class, 'webhook_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'event_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }
}
