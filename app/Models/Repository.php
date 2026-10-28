<?php

// OpenRiC Workspace - a repository: an institution or part of one that holds archives (ISDIAH).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use App\Description\Describable;
use App\Description\HasDates;
use App\Description\HasIdentifiers;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'iri', 'description_identifier', 'description_status_id', 'description_detail_id', 'complete_reference_codes', 'repository_type_id', 'upload_limit_gb'])]
class Repository extends Model
{
    use Audited, Describable, HasDates, HasIdentifiers, HasUuids;

    /** The URL path segment of its persistent IRI: {base IRI}/repository/{UUID}. */
    public const IRI_SEGMENT = 'repository';

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function contactPoints(): HasMany
    {
        return $this->hasMany(ContactPoint::class, 'subject_id')->where('subject_table', $this->getTable())
            ->orderByDesc('is_primary')->orderBy('created_at');
    }

    /** The units of description this repository holds. */
    public function records(): HasMany
    {
        return $this->hasMany(RecordResource::class);
    }

    /** Its type (ISDIAH 5.1.5), from the repository-type vocabulary. */
    public function repositoryType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'repository_type_id');
    }

    public function descriptionStatus(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'description_status_id');
    }

    public function descriptionDetail(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'description_detail_id');
    }
}
