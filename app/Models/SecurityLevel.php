<?php

// OpenRiC Workspace - a classification level of the institution (roadmap phase 18c, ADR 0016).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['institution_id', 'code', 'level', 'name', 'description', 'colour', 'requires_justification', 'requires_approval', 'requires_2fa', 'max_session_hours', 'watermark_required', 'download_allowed', 'print_allowed', 'copy_allowed', 'is_active'])]
class SecurityLevel extends Model
{
    use Audited, HasUuids;

    protected $table = 'security_levels';

    protected function casts(): array
    {
        return ['level' => 'integer', 'requires_justification' => 'boolean', 'requires_approval' => 'boolean', 'requires_2fa' => 'boolean', 'max_session_hours' => 'integer', 'watermark_required' => 'boolean', 'download_allowed' => 'boolean', 'print_allowed' => 'boolean', 'copy_allowed' => 'boolean', 'is_active' => 'boolean'];
    }
}
