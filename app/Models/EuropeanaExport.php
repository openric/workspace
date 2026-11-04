<?php

// OpenRiC Workspace - one Europeana export of an institution's published records and its bundle (Heratio's
// ahg_europeana_export; roadmap phase 16d).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['institution_id', 'status_id', 'since', 'record_count', 'storage_path', 'bundle_size', 'bundle_checksum', 'error', 'created_by_id', 'started_at', 'finished_at'])]
class EuropeanaExport extends Model
{
    use HasUuids;

    public $timestamps = false;
}
