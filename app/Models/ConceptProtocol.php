<?php

// OpenRiC Workspace - a community protocol on a term of the institution's own vocabularies (Heratio's term_protocol).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'concept_id', 'access_condition_id', 'label_id', 'community_id', 'region'])]
class ConceptProtocol extends Model
{
    use Audited, HasUuids;

    public function concept(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function accessCondition(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'access_condition_id');
    }

    public function label(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'label_id');
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'community_id');
    }
}
