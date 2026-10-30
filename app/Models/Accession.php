<?php

// OpenRiC Workspace - an accession: what the institution received, from whom and when (Heratio's ahg-accession-manage
// and AtoM's accession, with Heratio's CAAIS profile; exported as a rico:Activity; ADR 0010).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use App\Description\Describable;
use App\Description\HasDates;
use App\Description\HasIdentifiers;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'iri', 'repository_id', 'acquisition_type_id', 'resource_type_id', 'processing_status_id', 'processing_priority_id',
    'intake_status_id', 'intake_priority_id', 'assigned_to_id', 'submitted_at', 'accepted_at', 'rejected_at', 'rejection_reason', 'intake_notes'])]
class Accession extends Model
{
    use Audited, Describable, HasDates, HasIdentifiers, HasUuids;

    /** The URL path segment of its persistent IRI: {base IRI}/accession/{UUID}. */
    public const IRI_SEGMENT = 'accession';

    /** The OpenRiC activity type it is exported as: a transfer of custody into the institution. */
    public const ACTIVITY_TYPE = 'https://openric.org/vocab/activity-type/transfer';

    protected function casts(): array
    {
        return ['deleted_at' => 'immutable_datetime', 'submitted_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime', 'rejected_at' => 'immutable_datetime'];
    }

    /** CAAIS 1.1: the repository that accepts legal responsibility for the material. */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function acquisitionType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'acquisition_type_id');
    }

    public function resourceType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'resource_type_id');
    }

    public function processingStatus(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'processing_status_id');
    }

    public function processingPriority(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'processing_priority_id');
    }

    /** CAAIS 5.1 and AtoM's accession events. */
    public function events(): HasMany
    {
        return $this->hasMany(AccessionEvent::class)->orderBy('position')->orderBy('created_at');
    }

    /** CAAIS 3.2 extent statements. */
    public function extents(): HasMany
    {
        return $this->hasMany(AccessionExtent::class)->orderBy('position')->orderBy('created_at');
    }

    /** CAAIS 3.4 languages of the material. */
    public function languages(): HasMany
    {
        return $this->hasMany(AccessionLanguage::class)->orderBy('position')->orderBy('created_at');
    }

    /** CAAIS 4.3 preservation requirements. */
    public function preservationRequirements(): HasMany
    {
        return $this->hasMany(AccessionPreservationRequirement::class)->orderBy('position')->orderBy('created_at');
    }

    /** CAAIS 2.1.6, by source. */
    public function sourceConfidentiality(): HasMany
    {
        return $this->hasMany(AccessionSourceConfidentiality::class);
    }

    /** Its place in the intake workflow (PSIS's and Heratio's accession_v2). */
    public function intakeStatus(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'intake_status_id');
    }

    public function intakePriority(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'intake_priority_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    public function checklist(): HasMany
    {
        return $this->hasMany(AccessionChecklistItem::class)->orderBy('position')->orderBy('created_at');
    }

    /** Its chain of custody, the latest first. */
    public function timeline(): HasMany
    {
        return $this->hasMany(AccessionTimelineEvent::class)->orderByDesc('occurred_at')->orderByDesc('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(AccessionAttachment::class)->orderByDesc('created_at');
    }

    public function appraisals(): HasMany
    {
        return $this->hasMany(AccessionAppraisal::class)->orderByDesc('appraised_on')->orderByDesc('created_at');
    }

    /** Its valuations, the latest first: the first is its current value. */
    public function valuations(): HasMany
    {
        return $this->hasMany(AccessionValuation::class)->orderByDesc('valued_on')->orderByDesc('created_at');
    }

    public function deaccessions(): HasMany
    {
        return $this->hasMany(Deaccession::class)->orderBy('date')->orderBy('created_at');
    }

    /** The accession number: its local identifier. */
    public function number(): ?string
    {
        return $this->identifier;
    }

    /** Its number and title, as lists show it. */
    public function heading(array $languages = ['en']): string
    {
        return trim(implode(' ', array_filter([$this->number(), $this->described('accession-title', $languages)])));
    }
}
