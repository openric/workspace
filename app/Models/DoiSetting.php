<?php

// OpenRiC Workspace - how an institution mints DOIs for its units of description (Heratio's ahg_doi_config; roadmap phase
// 20c). Its DataCite account is the one its research services keep; the events token is encrypted.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['institution_id', 'auto_mint', 'auto_mint_level_ids', 'require_digital_object', 'default_publisher', 'default_resource_type_id', 'suffix_pattern', 'events_enabled', 'events_token'])]
class DoiSetting extends Model
{
    use Audited, HasUuids;

    protected $hidden = ['events_token'];

    protected function casts(): array
    {
        return ['auto_mint' => 'boolean', 'auto_mint_level_ids' => 'array', 'require_digital_object' => 'boolean', 'events_enabled' => 'boolean', 'events_token' => 'encrypted'];
    }

    public static function for(string $institutionId): self
    {
        return self::firstOrNew(['institution_id' => $institutionId], ['suffix_pattern' => '{repository_code}/{year}/{id}', 'auto_mint_level_ids' => []]);
    }
}
