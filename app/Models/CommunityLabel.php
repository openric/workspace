<?php

// OpenRiC Workspace - a Traditional Knowledge or Biocultural Label of Local Contexts on a record (WS-007, roadmap phase 2).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'subject_table', 'subject_id', 'label_id', 'community_id', 'applied_by_id', 'local_contexts_project', 'note'])]
class CommunityLabel extends Model
{
    use Audited, HasUuids;

    public function label(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'label_id');
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'community_id');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'applied_by_id');
    }
}
