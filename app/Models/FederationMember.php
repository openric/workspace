<?php

// OpenRiC Workspace - a member of an institution's union catalogue, the institution itself among them (Heratio's
// federation_member; roadmap phase 16b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['institution_id', 'name', 'base_url', 'contact', 'share_scope', 'is_self', 'is_enabled', 'last_fetched_at'])]
class FederationMember extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['is_self' => 'boolean', 'is_enabled' => 'boolean', 'last_fetched_at' => 'immutable_datetime'];
    }
}
