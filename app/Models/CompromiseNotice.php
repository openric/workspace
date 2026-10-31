<?php

// OpenRiC Workspace - a notice of a security compromise to the Regulator, the data subjects or others (Heratio's privacy_breach_notification); roadmap phase 18a, ADR 0016.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['security_compromise_id', 'recipient_id', 'method_id', 'recipient_name', 'sent_at', 'acknowledged_at', 'content', 'created_by_id'])]
class CompromiseNotice extends Model
{
    use Audited, HasUuids;

    protected $table = 'compromise_notices';

    protected function casts(): array
    {
        return ['sent_at' => 'immutable_datetime', 'acknowledged_at' => 'immutable_datetime'];
    }

    public function securityCompromise(): BelongsTo
    {
        return $this->belongsTo(SecurityCompromise::class, 'security_compromise_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'recipient_id');
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'method_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
