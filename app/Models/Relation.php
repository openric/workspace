<?php

// OpenRiC Workspace - a reified RiC relation between two entities (rico:Relation; ADR 0006).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use App\Description\HasDates;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'iri', 'relation_type_id', 'source_table', 'source_id', 'target_table', 'target_id', 'role_id',
    'context_table', 'context_id', 'state_id', 'certainty_id', 'source_of_information', 'description', 'language'])]
class Relation extends Model
{
    use Audited, HasDates, HasUuids;

    /** The URL path segment of its persistent IRI: {base IRI}/relation/{UUID}. */
    public const IRI_SEGMENT = 'relation';

    /** Set by RelationService::of(): the entity at the other end, seen from the page's entity. */
    public ?Model $other = null;

    /** Set by RelationService::of(): whether the page's entity is the source. */
    public bool $fromSource = true;

    public function relationType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'relation_type_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'role_id');
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'state_id');
    }

    public function certainty(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'certainty_id');
    }

    public function assertions(): HasMany
    {
        return $this->hasMany(RelationAssertion::class)->orderBy('asserted_at')->orderBy('id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(RelationEvidence::class)->orderBy('created_at');
    }

    /** Assertions no later assertion supersedes. */
    public function currentAssertions()
    {
        $superseded = $this->assertions->pluck('supersedes_id')->filter()->all();

        return $this->assertions->reject(fn (RelationAssertion $a) => in_array($a->id, $superseded, true))->values();
    }

    /**
     * Where the relation stands, from its current assertions: accepted (some accept it,
     * none reject it), disputed (some accept, some reject), proposed (only proposals
     * wait for review) or rejected.
     */
    public function standing(): string
    {
        $statuses = $this->currentAssertions()->map(fn (RelationAssertion $a) => $a->status->iri)->unique();
        $accepted = $statuses->contains(Vocab::BASE.'assertion-status/accepted');
        $rejected = $statuses->contains(Vocab::BASE.'assertion-status/rejected');

        return match (true) {
            $accepted && $rejected => 'disputed',
            $accepted => 'accepted',
            $statuses->contains(Vocab::BASE.'assertion-status/proposed') => 'proposed',
            default => 'rejected',
        };
    }

    /** How the relation reads from the page's entity. */
    public function phrase(): string
    {
        $type = config('workspace.relation_types.'.$this->relationType->notation);

        return $type ? __($this->fromSource ? $type['forward'] : $type['inverse']) : $this->relationType->label(Vocab::languages());
    }

    /**
     * The RiC-O term the relation is. For a type whose term depends on the class of the unit it comes from
     * (config relation_types.*.record_set_term), a record set's relation takes that term.
     */
    public function ricTerm(): ?string
    {
        $recordSetTerm = config('workspace.relation_types.'.$this->relationType->notation.'.record_set_term');
        if ($recordSetTerm !== null && $this->source_table === 'record_resources'
            && ($unit = RecordResource::with('level.labels')->find($this->source_id)) && EntityKinds::ricClass($unit) === 'rico:RecordSet') {
            return $recordSetTerm;
        }

        return $this->relationType->ricTerm();
    }

    public function datedAs(string $property): ?RicDate
    {
        return $this->dates->first(fn (RicDate $date) => $date->property->notation === $property);
    }
}
