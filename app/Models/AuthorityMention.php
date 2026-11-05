<?php

// OpenRiC Workspace - a name a description mentions, proposed as an agent or a place, with the context it stands in,
// under review for the authority record or place it is (Heratio's ahg_mention and ahg_mention_context; phase 17b2).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['institution_id', 'proposal_id', 'record_resource_id', 'state_id', 'assigned_to_id', 'assigned_by_id', 'assigned_at', 'assignment_note',
    'offset_start', 'offset_end', 'paragraph_start', 'paragraph_end', 'text_before', 'text_after', 'occurrences', 'ner_model', 'confidence',
    'co_occurring', 'nearby_dates', 'nearby_places', 'role_tokens', 'computed_at'])]
class AuthorityMention extends Model
{
    use Audited, HasUuids;

    /** spaCy's labels, which the evidence works in. */
    public const PERSON_ORG = ['PERSON', 'ORG'];

    public const PLACES = ['GPE', 'LOC', 'PLACE'];

    protected function casts(): array
    {
        return ['co_occurring' => 'array', 'nearby_dates' => 'array', 'nearby_places' => 'array', 'role_tokens' => 'array', 'confidence' => 'float',
            'assigned_at' => 'immutable_datetime', 'computed_at' => 'immutable_datetime'];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'state_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(AuthorityCandidate::class, 'mention_id')->orderBy('rank');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(AuthorityDecision::class, 'mention_id')->orderBy('decided_at');
    }

    public function park(): HasOne
    {
        return $this->hasOne(AuthorityPark::class, 'mention_id');
    }

    /** The name as proposed. */
    public function value(): string
    {
        return (string) $this->proposal->value;
    }

    /** spaCy's label for what the proposal says it is: PERSON, ORG or GPE. */
    public function label(): string
    {
        $proposal = $this->proposal;

        return $proposal->kind->notation === 'place' ? 'GPE' : ($proposal->agentType?->notation === 'person' ? 'PERSON' : 'ORG');
    }

    public function isPlace(): bool
    {
        return in_array($this->label(), self::PLACES, true);
    }
}
