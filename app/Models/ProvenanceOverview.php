<?php

// OpenRiC Workspace - the provenance overview of a unit of description (Heratio's provenance_overview): where the
// material stands now, how it was acquired, how certain and complete its provenance is, its due diligence and summary.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'record_resource_id', 'current_status_id', 'custody_type_id', 'acquisition_type_id', 'acquisition_date', 'acquisition_date_text',
    'acquisition_price', 'acquisition_currency', 'certainty_id', 'has_gaps', 'gap_description', 'research_status_id', 'research_notes', 'nazi_era_checked',
    'nazi_era_clear', 'nazi_era_notes', 'cultural_property_status_id', 'cultural_property_notes', 'summary', 'is_complete', 'is_public', 'created_by_id'])]
class ProvenanceOverview extends Model
{
    use Audited, HasUuids;

    /** Its concept columns and the vocabulary each takes its concept from. */
    public const TERMS = ['current_status' => 'provenance-current-status', 'custody_type' => 'provenance-custody-type', 'acquisition_type' => 'provenance-acquisition-type',
        'certainty' => 'provenance-certainty', 'research_status' => 'provenance-research-status', 'cultural_property_status' => 'cultural-property-status'];

    protected function casts(): array
    {
        return ['acquisition_date' => 'immutable_date', 'has_gaps' => 'boolean', 'nazi_era_checked' => 'boolean', 'nazi_era_clear' => 'boolean',
            'is_complete' => 'boolean', 'is_public' => 'boolean'];
    }

    public function concept(string $field): BelongsTo
    {
        return $this->belongsTo(Concept::class, $field.'_id');
    }

    public function currentStatus(): BelongsTo
    {
        return $this->concept('current_status');
    }

    public function custodyType(): BelongsTo
    {
        return $this->concept('custody_type');
    }

    public function acquisitionType(): BelongsTo
    {
        return $this->concept('acquisition_type');
    }

    public function certainty(): BelongsTo
    {
        return $this->concept('certainty');
    }

    public function researchStatus(): BelongsTo
    {
        return $this->concept('research_status');
    }

    public function culturalPropertyStatus(): BelongsTo
    {
        return $this->concept('cultural_property_status');
    }
}
