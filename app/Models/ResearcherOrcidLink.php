<?php

// OpenRiC Workspace - a researcher's ORCID iD, proven by signing in at ORCID; the tokens are encrypted (roadmap phase 19h, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['researcher_id', 'orcid', 'name', 'access_token', 'refresh_token', 'scope', 'expires_at', 'linked_at', 'works_pulled_at', 'works'])]
class ResearcherOrcidLink extends Model
{
    use Audited, HasUuids;

    protected $table = 'researcher_orcid_links';

    public $timestamps = false;

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return ['access_token' => 'encrypted', 'refresh_token' => 'encrypted', 'expires_at' => 'datetime', 'linked_at' => 'datetime', 'works_pulled_at' => 'datetime', 'works' => 'array'];
    }

    public function researcher(): BelongsTo
    {
        return $this->belongsTo(Researcher::class);
    }
}
