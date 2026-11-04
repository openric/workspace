<?php

// OpenRiC Workspace - a normalisation rule: files of a format or type, normalised into another by a tool, for
// preservation or for access (Heratio's preservation_normalization_rule, Archivematica's format policy; roadmap phase 15c).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['source_puid', 'source_mime', 'purpose_id', 'target_format', 'target_ext', 'target_mime', 'tool_id', 'options', 'priority', 'is_active'])]
class NormalisationRule extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['options' => 'array', 'priority' => 'integer', 'is_active' => 'boolean'];
    }

    public function purpose(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'purpose_id');
    }

    public function tool(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'tool_id');
    }
}
