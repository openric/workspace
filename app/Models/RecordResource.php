<?php

// OpenRiC Workspace - a record set, record or record part (ISAD(G); RiC-CM record resource).
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
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['institution_id', 'iri', 'level_id', 'repository_id', 'parent_id', 'position', 'description_identifier', 'description_status_id', 'description_detail_id', 'source_standard', 'display_as_compound', 'display_standard_id'])]
class RecordResource extends Model
{
    use Audited, Describable, HasDates, HasIdentifiers, HasUuids;

    /** The URL path segment of its persistent IRI: {base IRI}/record/{UUID}. */
    public const IRI_SEGMENT = 'record';

    public function displayStandard(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'display_standard_id');
    }

    /** The standard it is shown and edited in (display-standard notation): its own, else ISAD(G). */
    public function standard(): string
    {
        return $this->displayStandard?->notation ?? 'isad-g';
    }

    /** The types its display standards give it: RAD's general material designation, the DCMI type, the MODS type of resource. */
    public function resourceTypes(): HasMany
    {
        return $this->hasMany(RecordResourceType::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'level_id');
    }

    public function descriptionStatus(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'description_status_id');
    }

    public function descriptionDetail(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'description_detail_id');
    }

    /**
     * The full reference code (ISAD(G) 3.1.1): the repository's code, then the local
     * identifiers from the top of the hierarchy down. A unit whose identifier already
     * starts with the code above it (RPC/1 under RPC) is taken as written.
     */
    public function referenceCode(string $separator = '/'): ?string
    {
        $code = '';
        // A repository that gives every unit its whole code (as the Archives nationales de France do): the unit's own.
        $units = $this->repository?->complete_reference_codes && (string) $this->identifier !== '' ? [$this] : [...$this->ancestors(), $this];
        foreach ($units as $unit) {
            $own = (string) $unit->identifier;
            if ($own === '') {
                continue;
            }
            $code = ($code !== '' && ! str_starts_with($own, $code)) ? $code.$separator.$own : $own;
        }
        $repository = (string) $this->repository?->identifier;

        return $code === '' ? null : trim($repository.' '.$code);
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** rico:hasOrHadInstantiation: its carriers, such as digital files and paper copies, in order. */
    /** Its provenance overview (roadmap phase 9e). */
    public function provenanceOverview(): HasOne
    {
        return $this->hasOne(ProvenanceOverview::class);
    }

    /** Its provenance chain, the earliest step first; steps marked deleted are left out. */
    public function provenanceEntries(): HasMany
    {
        return $this->hasMany(ProvenanceEntry::class)->whereNull('deleted_at')->orderBy('position');
    }

    public function provenanceDocuments(): HasMany
    {
        return $this->hasMany(ProvenanceDocument::class)->orderByDesc('created_at');
    }

    public function instantiations(): HasMany
    {
        return $this->hasMany(Instantiation::class)->whereNull('deleted_at')->orderBy('position')->orderBy('created_at');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->whereNull('deleted_at')->orderBy('position');
    }

    /** Ancestors from the top down, not including this one. */
    public function ancestors(): array
    {
        $chain = [];
        for ($node = $this->parent; $node !== null; $node = $node->parent) {
            array_unshift($chain, $node);
        }

        return $chain;
    }

    /** Ids of every unit below this one. */
    public function descendantIds(): array
    {
        $ids = [];
        $frontier = [$this->id];
        while ($frontier !== []) {
            $frontier = self::whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }
}
