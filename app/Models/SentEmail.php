<?php

// OpenRiC Workspace - one message the Workspace sent, or held back (roadmap phase 7; Heratio's ahg_sent_email).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'mailable', 'recipient_email', 'user_id', 'subject', 'locale', 'audit_id', 'status_id', 'error', 'queued_at', 'sent_at'])]
class SentEmail extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['queued_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime'];
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
