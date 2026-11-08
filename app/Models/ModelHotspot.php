<?php

// OpenRiC Workspace - a labelled point on a 3D model (roadmap phase 21, 3D objects; Heratio's object_3d_hotspot).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'instantiation_id', 'type_id', 'position_x', 'position_y', 'position_z', 'normal_x', 'normal_y', 'normal_z', 'color', 'link_url', 'position', 'created_by_id'])]
class ModelHotspot extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['position_x' => 'float', 'position_y' => 'float', 'position_z' => 'float', 'normal_x' => 'float', 'normal_y' => 'float', 'normal_z' => 'float', 'position' => 'integer'];
    }

    public function instantiation(): BelongsTo
    {
        return $this->belongsTo(Instantiation::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'type_id');
    }

    public function texts(): HasMany
    {
        return $this->hasMany(ModelHotspotText::class, 'hotspot_id');
    }

    /** Its position and normal as model-viewer takes them: "x y z" in metres. */
    public function dataPosition(): string
    {
        return $this->position_x.'m '.$this->position_y.'m '.$this->position_z.'m';
    }

    public function dataNormal(): string
    {
        return $this->normal_x.'m '.$this->normal_y.'m '.$this->normal_z.'m';
    }
}
