<?php

// OpenRiC Workspace - how a relation type reads in one language: from its source (forward) and from its target
// (inverse, Heratio's converse term).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['relation_type_id', 'institution_id', 'language', 'forward', 'inverse'])]
class RelationTypePhrase extends Model
{
    use Audited, HasUuids;

    public function isAudited(): bool
    {
        return $this->institution_id !== null;
    }
}
