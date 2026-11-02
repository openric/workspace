<?php

// OpenRiC Workspace - a replication pack built for a research project (roadmap phase 19h, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'built_by_id', 'built_at', 'sha256', 'size_bytes', 'included'])]
class ResearchReplicationBuild extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_replication_builds';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['built_at' => 'datetime', 'included' => 'array'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }

    public function builtBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'built_by_id');
    }
}
