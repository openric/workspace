<?php

// OpenRiC Workspace - where a record's files must be screened for personal information before the public receives
// them (Heratio's settings privacy_screening_repositories and privacy_screening_collections; roadmap phase 18b,
// ADR 0016): a repository's records, or a record and everything inside it.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'repository_id', 'record_resource_id', 'note', 'created_by_id'])]
class ScreeningScope extends Model
{
    use Audited, HasUuids;

    protected $table = 'privacy_screening_scopes';

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }
}
