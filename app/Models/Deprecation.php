<?php

// OpenRiC Workspace - an entry of the deprecation register (Governance profile section 4): a deleted or merged entity's
// IRI, what it was, why it went and what replaced it (ADR 0013).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'subject_table', 'subject_id', 'iri', 'ric_class', 'name', 'was_public', 'reason', 'replaced_by_iri', 'deprecated_by_id', 'deprecated_at'])]
class Deprecation extends Model
{
    use Audited, HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['was_public' => 'boolean', 'deprecated_at' => 'immutable_datetime'];
    }

    public function deprecatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deprecated_by_id');
    }
}
