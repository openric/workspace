<?php

// OpenRiC Workspace - a lecture, the institution's or a researcher's own (roadmap phase 19j, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'user_id', 'type_id', 'title', 'subtitle', 'summary', 'speaker_name', 'speaker_affiliation', 'scheduled_at', 'location', 'duration_minutes', 'recording_url', 'slides_url', 'curriculum_ref', 'cover_record_id', 'status_id', 'sort_order'])]
class ResearchLecture extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_lectures';

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'duration_minutes' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function coverRecord(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'cover_record_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(ResearchLectureSection::class, 'lecture_id')->orderBy('sort_order');
    }

    public function resources(): HasMany
    {
        return $this->hasMany(ResearchLectureResource::class, 'lecture_id')->orderBy('sort_order');
    }
}
