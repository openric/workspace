<?php

// OpenRiC Workspace - one offline catalogue: a package of descriptions and files with a viewer that opens without a
// server (Heratio's portable_export; roadmap phase 11c).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'user_id', 'title', 'mode_id', 'status_id', 'repository_id', 'record_resource_id', 'scope_items', 'language', 'include_files',
    'include_masters', 'background_job_id', 'record_count', 'agent_count', 'file_count', 'withheld_count', 'storage_path', 'size_bytes', 'sha256', 'error',
    'started_at', 'finished_at'])]
class OfflineCatalogue extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['scope_items' => 'array', 'include_files' => 'boolean', 'include_masters' => 'boolean', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }

    /** Progress counts change while it is built; the audit keeps who asked for it and what it became. */
    public function notAudited(): array
    {
        return ['record_count', 'agent_count', 'file_count', 'withheld_count'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mode(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'mode_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(OfflineCatalogueShare::class);
    }

    /** The object in the store that holds the package. */
    public function objectId(): string
    {
        return Institution::findOrFail($this->institution_id)->base_iri.'/offline-catalogue/'.$this->id;
    }
}
