<?php

// OpenRiC Workspace - knowledge someone contributes about the object a repatriation claim concerns, shown once
// reviewed, its contributor named only with consent (Heratio's repatriation_knowledge_contribution; phase 17d2).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'claim_id', 'type_id', 'body', 'source', 'contributor_name', 'credit_consent', 'contributed_by_id', 'status_id', 'moderated_by_id', 'moderated_at'])]
class RepatriationKnowledge extends Model
{
    use Audited, HasUuids;

    protected $table = 'repatriation_knowledge';

    protected function casts(): array
    {
        return ['credit_consent' => 'boolean', 'moderated_at' => 'immutable_datetime'];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(RepatriationClaim::class, 'claim_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'type_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }
}
