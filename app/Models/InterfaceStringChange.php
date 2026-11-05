<?php

// OpenRiC Workspace - a change to the institution's wording of one interface string, proposed, accepted or rejected
// (Heratio's ui_string_change; roadmap phase 17c). Written by App\Translation\InterfaceStrings.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class InterfaceStringChange extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['*'];
}
