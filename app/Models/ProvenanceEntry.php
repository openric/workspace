<?php

// OpenRiC Workspace - a step in the provenance chain of a unit of description (Heratio's provenance_entry), a
// rico:Activity that affects the record, with its owner or holder taking part: who, when, how it passed on, at what
// price, how certain, and on what evidence.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'iri', 'record_resource_id', 'position', 'owner_id', 'owner_name', 'owner_type_id', 'owner_location', 'owner_location_tgn',
    'start_date', 'start_qualifier_id', 'end_date', 'end_qualifier_id', 'transfer_type_id', 'transfer_details', 'sale_price', 'sale_currency', 'auction_house',
    'auction_lot', 'certainty_id', 'sources', 'evidence_type_id', 'evidence_description', 'notes', 'is_gap', 'gap_explanation', 'deleted_at'])]
class ProvenanceEntry extends Model
{
    use Audited, HasUuids;

    /** The URL path segment of its persistent IRI: {base IRI}/provenance/{UUID}. */
    public const IRI_SEGMENT = 'provenance';

    protected function casts(): array
    {
        return ['is_gap' => 'boolean', 'deleted_at' => 'immutable_datetime'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'owner_id');
    }

    public function ownerType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'owner_type_id');
    }

    public function startQualifier(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'start_qualifier_id');
    }

    public function endQualifier(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'end_qualifier_id');
    }

    public function transferType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'transfer_type_id');
    }

    public function certainty(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'certainty_id');
    }

    public function evidenceType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'evidence_type_id');
    }

    /** Its dates as written: "circa 1920 to 1945". */
    public function period(array $languages = ['en']): string
    {
        $date = fn (?string $day, ?Concept $qualifier) => $day === null ? null : trim(($qualifier ? mb_strtolower($qualifier->label($languages)).' ' : '').$day);
        [$from, $to] = [$date($this->start_date, $this->startQualifier), $date($this->end_date, $this->endQualifier)];

        return match (true) {
            $from !== null && $to !== null => __(':from to :to', ['from' => $from, 'to' => $to]),
            $from !== null => __('from :date', ['date' => $from]),
            $to !== null => __('until :date', ['date' => $to]),
            default => __('date unknown'),
        };
    }
}
