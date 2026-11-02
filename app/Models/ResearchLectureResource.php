<?php

// OpenRiC Workspace - a resource of a lecture (roadmap phase 19j, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['lecture_id', 'label', 'url', 'resource_type_id', 'sort_order'])]
class ResearchLectureResource extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_lecture_resources';

    public function lecture(): BelongsTo
    {
        return $this->belongsTo(ResearchLecture::class);
    }

    public function resourceType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }
}
