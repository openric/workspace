<?php

// OpenRiC Workspace - an entry of a bibliography: a record of the catalogue, or a book, article or other work (Heratio's research_bibliography_entry; roadmap phase 19c).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['bibliography_id', 'record_resource_id', 'entry_type_id', 'csl_data', 'title', 'authors', 'date', 'publisher', 'container_title', 'volume', 'issue', 'pages', 'doi', 'url', 'accessed_on', 'archive_name', 'archive_location', 'collection_title', 'box', 'folder', 'notes', 'position'])]
class BibliographyEntry extends Model
{
    use Audited, HasUuids;

    protected $table = 'bibliography_entries';

    protected function casts(): array
    {
        return ['csl_data' => 'array', 'accessed_on' => 'immutable_date'];
    }

    public function bibliography(): BelongsTo
    {
        return $this->belongsTo(Bibliography::class);
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function entryType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'entry_type_id');
    }
}
