<?php

// OpenRiC Workspace - an item of a curated IIIF collection (Heratio's iiif_collection_item): a record of the
// institution, another curated collection, or another institution's manifest or collection by its URI.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['iiif_collection_id', 'record_resource_id', 'member_collection_id', 'uri', 'resource_type_id', 'label', 'position'])]
class IiifCollectionItem extends Model
{
    use Audited, HasUuids;

    public function collection(): BelongsTo
    {
        return $this->belongsTo(IiifCollection::class, 'iiif_collection_id');
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function memberCollection(): BelongsTo
    {
        return $this->belongsTo(IiifCollection::class, 'member_collection_id');
    }

    public function resourceType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'resource_type_id');
    }
}
