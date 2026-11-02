<?php

// OpenRiC Workspace - how a researcher wants notices of one kind (roadmap phase 19j, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'type_id', 'email_enabled', 'in_app_enabled', 'digest_id', 'last_digest_at'])]
class ResearchNotificationPreference extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_notification_preferences';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['email_enabled' => 'boolean', 'in_app_enabled' => 'boolean', 'last_digest_at' => 'datetime'];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function digest(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }
}
