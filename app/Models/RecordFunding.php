<?php

// OpenRiC Workspace - who funded the work a unit of description records, for its DOI's fundingReferences (Heratio's
// ahg_io_funding; roadmap phase 20c).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['record_resource_id', 'funder_name', 'funder_identifier', 'funder_identifier_type_id', 'award_number', 'award_uri', 'award_title'])]
class RecordFunding extends Model
{
    use Audited, HasUuids;

    protected $table = 'record_funding';

    public function identifierType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'funder_identifier_type_id');
    }
}
