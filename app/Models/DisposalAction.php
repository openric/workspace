<?php

// OpenRiC Workspace - a disposal of a record, from its initiation through recommendation, approval and legal clearance to its being carried out, after Heratio's rm_disposal_action (roadmap phase 13b, ADR 0015).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'record_resource_id', 'disposal_class_id', 'action_id', 'status_id', 'reason', 'notes', 'initiated_by_id', 'initiated_at', 'recommended_by_id', 'recommended_at', 'approved_by_id', 'approved_at', 'cleared_by_id', 'cleared_at', 'executed_by_id', 'executed_at', 'transfer_destination', 'certificate_id', 'verification', 'verified_at'])]
class DisposalAction extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['initiated_at' => 'immutable_datetime', 'recommended_at' => 'immutable_datetime', 'approved_at' => 'immutable_datetime', 'cleared_at' => 'immutable_datetime', 'executed_at' => 'immutable_datetime', 'verification' => 'array', 'verified_at' => 'immutable_datetime'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function disposalClass(): BelongsTo
    {
        return $this->belongsTo(DisposalClass::class, 'disposal_class_id');
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'action_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_id');
    }

    public function recommendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recommended_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function clearedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleared_by_id');
    }

    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by_id');
    }

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(DestructionCertificate::class, 'certificate_id');
    }
}
