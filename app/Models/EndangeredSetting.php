<?php

// OpenRiC Workspace - an institution's choices for its register of heritage at risk (phase 17d3): whether the public
// catalogue shows it, whether peers may read it, and whether peers may push flags to it.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EndangeredSetting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'institution_id';

    protected $keyType = 'string';

    protected $fillable = ['institution_id', 'public_register', 'share_with_peers', 'accept_pushes'];

    protected $attributes = ['public_register' => false, 'share_with_peers' => false, 'accept_pushes' => false];

    protected function casts(): array
    {
        return ['public_register' => 'boolean', 'share_with_peers' => 'boolean', 'accept_pushes' => 'boolean'];
    }

    public static function for(string $institutionId): self
    {
        return self::find($institutionId) ?? new self(['institution_id' => $institutionId]);
    }
}
