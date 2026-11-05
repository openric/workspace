<?php

// OpenRiC Workspace - a proposed agent or place made a mention under review (roadmap phase 17b2), after Heratio's
// PromoteToMentionService: its context is derived from the text the name stands in (the unit's description and the
// transcripts of its pages, the text names are found in), with the unit's other proposed names, dates and places and
// the institution's words of role; then its candidates are generated and scored. A name found by spaCy keeps the exact
// offsets and score spaCy gave; one sent through the API is found in the text.
// Changed from Heratio: the mention is a proposal of the review queue, not a row of a NER table, so names from the API
// are resolved too; it is made when the name is found, or when it is first opened for review.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Authority;

use App\Ai\Assist;
use App\Models\AuthorityMention;
use App\Models\Concept;
use App\Models\Proposal;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Mentions
{
    public function __construct(private MentionContext $context, private Settings $settings, private Candidates $candidates, private Scorer $scorer) {}

    /**
     * The mention of an agent or place proposal, made with its context and scored candidates if it is not yet one.
     *
     * @param  array{start: int, end: int}|null  $offset  where spaCy found it, in characters
     */
    public function promote(Proposal $proposal, ?array $offset = null, ?float $confidence = null, ?string $model = null): AuthorityMention
    {
        $proposal->loadMissing('kind', 'agentType', 'record', 'inference');
        in_array($proposal->kind->notation, ['agent', 'place'], true) || throw new InvalidArgumentException(__('Only a proposed person, organisation or place is resolved to an authority.'));
        if ($mention = AuthorityMention::where('proposal_id', $proposal->id)->first()) {
            return $mention;
        }
        $record = $proposal->record;
        $others = Proposal::where('record_resource_id', $record->id)->whereKeyNot($proposal->id)->with('kind', 'agentType')->get()
            ->filter(fn (Proposal $p) => in_array($p->kind->notation, ['agent', 'place', 'date'], true))
            ->map(fn (Proposal $p) => ['value' => (string) $p->value, 'type' => match ($p->kind->notation) {
                'place' => 'GPE', 'date' => 'DATE', default => $p->agentType?->notation === 'person' ? 'PERSON' : 'ORG'
            }])->values()->all();
        $packet = $this->context->derive(app(Assist::class)->text($record), (string) $proposal->value, $others, $this->settings->roleTokens($record->institution_id), $offset);

        $mention = DB::transaction(fn () => AuthorityMention::create(['institution_id' => $record->institution_id, 'proposal_id' => $proposal->id,
            'record_resource_id' => $record->id, 'state_id' => self::state('pending'), 'confidence' => $confidence ?? $proposal->confidence,
            'ner_model' => $model ?? $proposal->inference?->model, 'computed_at' => now()] + $packet));
        $this->candidates->generate($mention);
        $this->scorer->scoreAll($mention);

        return $mention->fresh();
    }

    public static function state(string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.'mention-state/'.$notation)->valueOrFail('id');
    }
}
