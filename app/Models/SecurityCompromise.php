<?php

// OpenRiC Workspace - a security compromise of personal information (POPIA s.22; Heratio's privacy_breach); roadmap phase 18a, ADR 0016.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'reference', 'jurisdiction_id', 'type_id', 'severity_id', 'status_id', 'description', 'detected_at', 'occurred_at', 'contained_at', 'resolved_at', 'subjects_affected', 'categories_affected', 'risk', 'notification_required', 'assigned_to_id', 'created_by_id'])]
class SecurityCompromise extends Model
{
    use Audited, HasUuids;

    protected $table = 'security_compromises';

    protected function casts(): array
    {
        return ['detected_at' => 'immutable_datetime', 'occurred_at' => 'immutable_datetime', 'contained_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime', 'subjects_affected' => 'integer', 'notification_required' => 'boolean'];
    }

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(PrivacyJurisdiction::class, 'jurisdiction_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'type_id');
    }

    public function severity(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'severity_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function notices(): HasMany
    {
        return $this->hasMany(CompromiseNotice::class)->orderBy('sent_at');
    }
}
