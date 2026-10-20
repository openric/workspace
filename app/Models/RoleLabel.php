<?php

// OpenRiC Workspace - a role's name and description in one language.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['role_id', 'language', 'name', 'description'])]
class RoleLabel extends Model
{
    use Audited;

    // The key is (role_id, language); role_id is what the audit log names it by,
    // and setKeysForSaveQuery adds the language to every update and delete.
    protected $primaryKey = 'role_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected function setKeysForSaveQuery($query)
    {
        return $query->where('role_id', $this->role_id)->where('language', $this->language);
    }
}
