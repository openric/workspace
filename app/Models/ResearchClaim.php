<?php

// OpenRiC Workspace - a claim of a research project's claim ledger, or an assertion about an entity (roadmap phase 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'project_id', 'user_id', 'claim_type_id', 'subject_table', 'subject_id', 'subject_label', 'predicate', 'object_value', 'object_table', 'object_id', 'object_label', 'status_id', 'confidence', 'version', 'evidence_type_id', 'confidence_level_id', 'provenance_kind_id', 'supporting_sources', 'opposing_sources', 'quotations', 'method_theory_link', 'researcher_notes', 'unresolved_weaknesses', 'ethical_concerns'])]
class ResearchClaim extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_claims';

    protected function casts(): array
    {
        return ['confidence' => 'float', 'version' => 'integer'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function claimType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function evidenceType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function confidenceLevel(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function provenanceKind(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(ResearchClaimEvidence::class, 'claim_id')->orderBy('created_at');
    }
}
