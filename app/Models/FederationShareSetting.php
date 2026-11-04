<?php

// OpenRiC Workspace - what an institution shares with the peers that search it (Heratio's federation_share_setting;
// roadmap phase 16a): whether it shares at all, and which levels of description. Only what is published is ever shared.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['institution_id', 'share_enabled', 'levels', 'require_verified', 'shared_vocabularies'])]
class FederationShareSetting extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['share_enabled' => 'boolean', 'levels' => 'array', 'require_verified' => 'boolean', 'shared_vocabularies' => 'array'];
    }

    public static function for(string $institutionId): self
    {
        return self::firstOrNew(['institution_id' => $institutionId], ['share_enabled' => false]);
    }
}
