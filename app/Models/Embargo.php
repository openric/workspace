<?php

// OpenRiC Workspace - an embargo on a unit of description and its files (Heratio's embargo; roadmap phase 13a,
// ADR 0015): in force from its first day to its last, or until lifted when it has none, unless lifted.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'record_resource_id', 'type_id', 'reason_id', 'note', 'public_message', 'starts_on', 'ends_on', 'notify_days_before', 'warned_on',
    'ended_notice_at', 'lifted_at', 'lifted_by_id', 'lift_reason', 'created_by_id'])]
class Embargo extends Model
{
    use Audited, HasUuids;

    protected $table = 'embargoes';

    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'ends_on' => 'immutable_date', 'warned_on' => 'immutable_date', 'ended_notice_at' => 'immutable_datetime',
            'lifted_at' => 'immutable_datetime', 'notify_days_before' => 'integer'];
    }

    /** In force on a day: begun, not ended, not lifted. */
    public function scopeInForce(Builder $query, ?CarbonImmutable $on = null): Builder
    {
        $day = ($on ?? CarbonImmutable::now(config('workspace.timezone')))->toDateString();

        return $query->whereNull('lifted_at')->where('starts_on', '<=', $day)->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $day));
    }

    public function inForce(?CarbonImmutable $on = null): bool
    {
        $day = ($on ?? CarbonImmutable::now(config('workspace.timezone')))->toDateString();

        return $this->lifted_at === null && $this->starts_on->toDateString() <= $day && ($this->ends_on === null || $this->ends_on->toDateString() >= $day);
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'type_id');
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'reason_id');
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(EmbargoException::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
