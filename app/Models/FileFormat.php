<?php

// OpenRiC Workspace - a file format as PRONOM identifies it, with its risk and the institution's action for it (Heratio's
// preservation_format; roadmap phase 15a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['puid', 'name', 'version', 'mime_type', 'format_class', 'risk_id', 'action_id', 'is_preservation_format', 'notes'])]
class FileFormat extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['is_preservation_format' => 'boolean'];
    }

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'risk_id');
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'action_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(DigitalFile::class, 'format_id');
    }

    /** Its page at The National Archives (UK), which keeps PRONOM. */
    public function pronomUrl(): string
    {
        return 'https://www.nationalarchives.gov.uk/PRONOM/'.$this->puid;
    }
}
