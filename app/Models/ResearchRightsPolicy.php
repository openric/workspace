<?php

// OpenRiC Workspace - an ODRL rights policy on a record, for researchers (roadmap phase 19j, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'record_resource_id', 'policy_type_id', 'action_id', 'constraints', 'note', 'created_by_id'])]
class ResearchRightsPolicy extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_rights_policies';

    protected function casts(): array
    {
        return ['constraints' => 'array'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function policyType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }
}
