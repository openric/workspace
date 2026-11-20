<?php

// OpenRiC Workspace - the explorer settings of a record set (roadmap phase 27); a set without a row takes the defaults:
// nothing hidden, a name on one unit is enough, and only reviewed names from the text.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['institution_id', 'record_resource_id', 'hide', 'min_mentions', 'unreviewed', 'min_confidence'])]
class ExplorerSetting extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['min_mentions' => 'integer', 'unreviewed' => 'boolean', 'min_confidence' => 'float'];
    }

    public static function for(RecordResource $set): self
    {
        return self::firstWhere('record_resource_id', $set->id)
            ?? new self(['institution_id' => $set->institution_id, 'record_resource_id' => $set->id, 'min_mentions' => 1, 'unreviewed' => false]);
    }
}
