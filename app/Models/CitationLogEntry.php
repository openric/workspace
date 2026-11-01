<?php

// OpenRiC Workspace - a record cited, in which format (Heratio's research_citation_log; roadmap phase 19c).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['institution_id', 'user_id', 'record_resource_id', 'format', 'created_at'])]
class CitationLogEntry extends Model
{
    use HasUuids;

    protected $table = 'citation_log';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
