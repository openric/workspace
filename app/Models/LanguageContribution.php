<?php

// OpenRiC Workspace - a transcription, correction, translation or note about a record in a language, shown once reviewed and named only with consent (Heratio's language_transcription_contribution; phase 17d4).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'record_resource_id', 'language', 'type_id', 'body', 'source', 'contributor_name', 'credit_consent', 'status_id', 'contributed_by_id', 'moderated_by_id', 'moderated_at'])]
class LanguageContribution extends Model
{
    use Audited, HasUuids;

    protected $table = 'language_contributions';

    protected function casts(): array
    {
        return ['credit_consent' => 'boolean', 'moderated_at' => 'immutable_datetime'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'type_id');
    }
}
