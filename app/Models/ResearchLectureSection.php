<?php

// OpenRiC Workspace - a section of a lecture (roadmap phase 19j, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['lecture_id', 'heading', 'body', 'media_url', 'media_type_id', 'sort_order'])]
class ResearchLectureSection extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_lecture_sections';

    public function lecture(): BelongsTo
    {
        return $this->belongsTo(ResearchLecture::class);
    }

    public function mediaType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }
}
