<?php

// OpenRiC Workspace - a reviewer's attestation that they know how to review AI output (roadmap phase 17f; Heratio's ai_operator_attestation).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['institution_id', 'user_id', 'version', 'attested_at', 'expires_at'])]
class AiAttestation extends Model
{
    use Audited, HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['attested_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }
}
