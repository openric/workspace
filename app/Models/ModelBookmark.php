<?php

// OpenRiC Workspace - a saved view of a 3D model, a reader's own or published by staff (roadmap phase 21, 3D objects; Heratio's object_3d_camera_bookmark).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['institution_id', 'instantiation_id', 'user_id', 'name', 'camera_orbit', 'camera_target', 'field_of_view', 'is_public'])]
class ModelBookmark extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['is_public' => 'boolean'];
    }
}
