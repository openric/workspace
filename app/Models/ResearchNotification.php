<?php

// OpenRiC Workspace - a notice to a researcher (roadmap phase 19j, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'type_id', 'title', 'message', 'link', 'in_app', 'email_due', 'emailed_at', 'read_at', 'created_at'])]
class ResearchNotification extends Model
{
    use HasUuids;

    protected $table = 'research_notifications';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['in_app' => 'boolean', 'email_due' => 'boolean', 'emailed_at' => 'datetime', 'read_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }
}
