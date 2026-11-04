<?php

// OpenRiC Workspace - a file in an information package, with its path in the bag and its checksum (Heratio's
// preservation_package_object; roadmap phase 15d).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['package_id', 'digital_file_id', 'relative_path', 'file_name', 'file_size', 'checksum_algorithm', 'checksum_value', 'mime_type', 'puid', 'role_id', 'sequence', 'created_at'])]
class PackageFile extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['file_size' => 'integer', 'sequence' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'package_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'role_id');
    }
}
