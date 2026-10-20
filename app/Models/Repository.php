<?php

// OpenRiC Workspace - a repository: an institution or part of one that holds archives (ISDIAH).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use App\Description\Describable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'iri', 'identifier'])]
class Repository extends Model
{
    use Audited, Describable, HasUuids;

    /** The URL path segment of its persistent IRI: {base IRI}/repository/{UUID}. */
    public const IRI_SEGMENT = 'repository';

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }
}
