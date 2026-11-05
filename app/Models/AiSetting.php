<?php

// OpenRiC Workspace - an institution's AI settings (roadmap phase 17a): whether it uses the AI gateway at all, a monthly
// limit on calls, and the vision models that read printed pages.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['institution_id', 'enabled', 'monthly_limit', 'ocr_model', 'ocr_fallback_model', 'chat_model', 'translation_target', 'translation_elements', 'updated_by_id'])]
class AiSetting extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'monthly_limit' => 'integer', 'translation_elements' => 'array'];
    }
}
