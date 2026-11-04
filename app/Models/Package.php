<?php

// OpenRiC Workspace - an OAIS information package of a unit of description, a SIP, AIP or DIP serialised as a BagIt
// bag (Heratio's preservation_package; roadmap phase 15d).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'record_resource_id', 'name', 'description', 'type_id', 'status_id', 'format_id', 'bagit_version', 'manifest_algorithm', 'include_descendants', 'object_count', 'total_size', 'storage_path', 'package_checksum', 'package_size', 'originator', 'submission_agreement', 'retention_period', 'parent_package_id', 'metadata', 'created_by_id', 'built_at', 'validated_at', 'exported_at'])]
class Package extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['include_descendants' => 'boolean', 'object_count' => 'integer', 'total_size' => 'integer', 'package_size' => 'integer', 'metadata' => 'array', 'built_at' => 'immutable_datetime', 'validated_at' => 'immutable_datetime', 'exported_at' => 'immutable_datetime'];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'type_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function format(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'format_id');
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'parent_package_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Package::class, 'parent_package_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(PackageFile::class, 'package_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PackageEvent::class, 'package_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
