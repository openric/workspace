<?php

// OpenRiC Workspace - an institution's choices for repatriation claims (phase 17d2): whether the public catalogue shows
// the register of claims, whether the public may lodge one,
// and whether staff and claimants are told of them.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RepatriationSetting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'institution_id';

    protected $keyType = 'string';

    protected $fillable = ['institution_id', 'public_register', 'public_lodging', 'notify'];

    protected $attributes = ['public_register' => false, 'public_lodging' => false, 'notify' => true];

    protected function casts(): array
    {
        return ['public_register' => 'boolean', 'public_lodging' => 'boolean', 'notify' => 'boolean'];
    }

    public static function for(string $institutionId): self
    {
        return self::find($institutionId) ?? new self(['institution_id' => $institutionId]);
    }
}
