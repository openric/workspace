<?php

// OpenRiC Workspace - a reminder of a donor agreement (Heratio's donor_agreement_reminder): what, when, how far ahead,
// how often, to whom, and whether it was dealt with.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['institution_id', 'agreement_id', 'type_id', 'subject', 'description', 'remind_on', 'advance_days', 'recurrence_id', 'recurrence_ends_on',
    'priority_id', 'notify_email', 'status_id', 'snoozed_until', 'action_required', 'sent_for', 'sent_at', 'completed_at', 'completed_by_id',
    'completion_note', 'created_by_id'])]
class AgreementReminder extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['remind_on' => 'immutable_date', 'recurrence_ends_on' => 'immutable_date', 'snoozed_until' => 'immutable_date', 'sent_for' => 'immutable_date',
            'sent_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'notify_email' => 'boolean', 'advance_days' => 'integer'];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'type_id');
    }

    public function recurrence(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'recurrence_id');
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'priority_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** The accounts it is sent to; with none, the account that made it. */
    public function recipients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'agreement_reminder_recipients', 'reminder_id', 'user_id');
    }
}
