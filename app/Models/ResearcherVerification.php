<?php

// OpenRiC Workspace - a check of a researcher's identity or affiliation (Heratio's research_verification; roadmap phase 19b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['researcher_id', 'type_id', 'document_type', 'document_reference', 'document_file_id', 'status_id', 'verified_by_id', 'verified_at', 'expires_on', 'rejection_reason', 'notes'])]
class ResearcherVerification extends Model
{
    use Audited, HasUuids;

    protected $table = 'researcher_verifications';

    protected function casts(): array
    {
        return ['verified_at' => 'immutable_datetime', 'expires_on' => 'immutable_date'];
    }

    public function researcher(): BelongsTo
    {
        return $this->belongsTo(Researcher::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'type_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ResearchFile::class, 'document_file_id');
    }
}
