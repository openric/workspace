<?php

// OpenRiC Workspace - a repatriation claim: a documented request that an object a description holds belongs with a
// community elsewhere, and where the dialogue around it stands (Heratio's displaced_heritage_claim; phase 17d2).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'record_resource_id', 'status_id', 'claimant_community', 'claimant_agent_id', 'origin_place', 'origin_place_id', 'current_holder',
    'evidence_summary', 'contact', 'contact_email', 'notes', 'lodged_publicly', 'created_by_id'])]
class RepatriationClaim extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['lodged_publicly' => 'boolean'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function claimantAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'claimant_agent_id');
    }

    public function originPlace(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'origin_place_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(RepatriationMessage::class, 'claim_id')->orderBy('id');
    }

    public function statusChanges(): HasMany
    {
        return $this->hasMany(RepatriationStatusChange::class, 'claim_id')->orderByDesc('id');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(RepatriationShare::class, 'claim_id')->orderByDesc('created_at');
    }

    public function knowledge(): HasMany
    {
        return $this->hasMany(RepatriationKnowledge::class, 'claim_id')->orderByDesc('created_at');
    }
}
