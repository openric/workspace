<?php

// OpenRiC Workspace - how an institution shows its 3D models by default (roadmap phase 21, 3D objects; Heratio's viewer_3d_settings).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['institution_id', 'auto_rotate', 'rotation_speed', 'camera_orbit', 'min_camera_orbit', 'max_camera_orbit', 'field_of_view', 'exposure', 'shadow_intensity', 'shadow_softness', 'neutral_lighting', 'background_color', 'ar_enabled', 'ar_fixed_scale', 'ar_placement_id'])]
class ModelViewerDefault extends Model
{
    use Audited;

    public const FIELDS = ['auto_rotate', 'rotation_speed', 'camera_orbit', 'min_camera_orbit', 'max_camera_orbit', 'field_of_view', 'exposure', 'shadow_intensity', 'shadow_softness', 'neutral_lighting', 'background_color', 'ar_enabled', 'ar_fixed_scale', 'ar_placement_id'];

    protected $primaryKey = 'institution_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return ['auto_rotate' => 'boolean', 'neutral_lighting' => 'boolean', 'ar_enabled' => 'boolean', 'ar_fixed_scale' => 'boolean', 'rotation_speed' => 'integer', 'exposure' => 'float', 'shadow_intensity' => 'float', 'shadow_softness' => 'float'];
    }
}
