<?php

// OpenRiC Workspace - a package from Archivematica and the unit of description its files were kept with (Heratio's
// am_link; roadmap phase 15d).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'record_resource_id', 'transfer_uuid', 'sip_uuid', 'aip_uuid', 'dip_uuid', 'pipeline_uuid', 'status_id', 'files_taken', 'note'])]
class ArchivematicaLink extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['files_taken' => 'integer'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }
}
