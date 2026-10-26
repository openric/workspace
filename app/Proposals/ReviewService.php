<?php

// OpenRiC Workspace - reviewing proposals (ADR 0007, step 3): accepting one writes it through the record's own
// services, as an edit by the reviewer, so it is audited and waits for publication as any other change.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Heratio's AI review screens (ahg-ai-services) are followed for what a reviewer
// may do: approve, approve as edited, or reject with a note. What approval writes
// is the Workspace's: a value or translation becomes the record's value of that
// element in that language; a transcript the instantiation's transcript; a person,
// organisation or place an access point of the record (a subject or place
// relation), to the existing authority record or place the reviewer chooses or to
// a new one; a date a date associated with the record.

namespace App\Proposals;

use App\Audit\AuditLog;
use App\Description\AgentService;
use App\Description\Bcp47;
use App\Description\DateService;
use App\Description\InstantiationService;
use App\Description\PlaceService;
use App\Description\RecordService;
use App\Models\Agent;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\Place;
use App\Models\Proposal;
use App\Models\Relation;
use App\Models\User;
use App\Relations\RelationService;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ReviewService
{
    /** What a person or organisation proposal knows, as ISAAR(CPF) elements of the new authority record. */
    private const AUTHORITY_ELEMENTS = ['dates_of_existence' => 'agent-dates-of-existence', 'history' => 'agent-history', 'places' => 'agent-places'];

    public function __construct(private RelationService $relations) {}

    /**
     * Accept the proposal, as the reviewer may have changed it. Edits: value (the text to
     * write), language (of a value or transcript), normalized (EDTF, for a date), agent_type
     * (of a new authority record), link (the key of the agent or place to use, or "new"),
     * note. Returns the entity written to.
     */
    public function accept(Proposal $proposal, User $reviewer, array $edits = []): Model
    {
        $this->waiting($proposal);
        $record = $proposal->record;
        $value = trim((string) ($edits['value'] ?? $proposal->value));
        if ($value === '') {
            throw new InvalidArgumentException('give the text to write, or reject the proposal');
        }
        $language = trim((string) ($edits['language'] ?? $proposal->language ?? $record->originalLanguage('title')));
        if (! Bcp47::valid($language)) {
            throw new InvalidArgumentException('give the language as a BCP 47 tag, such as en or af');
        }

        return DB::transaction(fn () => AuditLog::actingAs($reviewer, function () use ($proposal, $reviewer, $edits, $record, $value, $language) {
            $kind = $proposal->kind->notation;
            $written = match ($kind) {
                'value' => tap($record, fn () => app(RecordService::class)->describe($record, [$proposal->element->notation => $value], $language, $reviewer)),
                'transcript' => tap($proposal->instantiation, fn ($i) => app(InstantiationService::class)->describe($i, ['instantiation-transcript' => $value], $language, $reviewer)),
                'date' => tap($record, fn () => app(DateService::class)->add($record, ['property' => 'associated', 'expressed' => $value, 'language' => $language,
                    'normalized' => $edits['normalized'] ?? null], $reviewer)),
                'agent', 'place' => $this->accessPoint($proposal, $reviewer, $edits, $value, $language),
            };
            $proposal->update([
                'status_id' => $this->concept(Vocab::ASSERTION_STATUSES.'accepted')->id, 'reviewed_by_id' => $reviewer->id, 'reviewed_at' => now(),
                'review_note' => $this->note($edits),
                ...(in_array($kind, ['agent', 'place'], true) ? ['linked_table' => $written->getTable(), 'linked_id' => $written->getKey()] : []),
            ]);

            return $written;
        }));
    }

    public function reject(Proposal $proposal, User $reviewer, ?string $note = null): void
    {
        $this->waiting($proposal);
        DB::transaction(fn () => AuditLog::actingAs($reviewer, fn () => $proposal->update([
            'status_id' => $this->concept(Vocab::ASSERTION_STATUSES.'rejected')->id, 'reviewed_by_id' => $reviewer->id, 'reviewed_at' => now(),
            'review_note' => $this->note(['note' => $note]),
        ])));
    }

    /** The agent or place the proposal names, chosen or made, related to the record as an access point unless it is already. */
    private function accessPoint(Proposal $proposal, User $reviewer, array $edits, string $value, string $language): Model
    {
        $record = $proposal->record;
        $institution = Institution::findOrFail($record->institution_id);
        $isAgent = $proposal->kind->notation === 'agent';
        $class = $isAgent ? Agent::class : Place::class;
        $link = trim((string) ($edits['link'] ?? ($proposal->linked_id ?? 'new')));
        if ($link !== 'new') {
            $entity = $class::where('institution_id', $institution->id)->whereNull('deleted_at')->find($link)
                ?? throw new InvalidArgumentException($isAgent ? 'that authority record does not exist' : 'that place does not exist');
        } elseif ($isAgent) {
            if (! $reviewer->may('create', 'authority')) {
                throw new InvalidArgumentException('you may not create authority records: choose an existing one');
            }
            $entity = app(AgentService::class)->create($institution, (string) ($edits['agent_type'] ?? $proposal->agentType?->notation ?? 'person'), $value, $language, null, $reviewer);
            $known = array_filter(array_map(fn ($k) => $proposal->authority[$k] ?? null, array_flip(self::AUTHORITY_ELEMENTS)));
            if ($known !== []) {
                app(AgentService::class)->describe($entity, $known, $language, $reviewer);
            }
        } else {
            if (! $reviewer->may('create', 'vocabulary')) {
                throw new InvalidArgumentException('you may not create places: choose an existing one');
            }
            $entity = app(PlaceService::class)->create($institution, $value, $language, null, $reviewer);
        }

        [$type, $source, $target] = $isAgent ? ['subject', $record, $entity] : ['place', $entity, $record];
        $exists = Relation::whereHas('relationType', fn ($q) => $q->where('iri', Vocab::BASE.'relation-type/'.$type))
            ->where(['source_table' => $source->getTable(), 'source_id' => $source->getKey(), 'target_table' => $target->getTable(), 'target_id' => $target->getKey()])
            ->with('assertions.status')->get()->contains(fn (Relation $r) => $r->standing() !== 'rejected');
        if (! $exists) {
            $this->relations->create($source, $type, $target, [], $reviewer);
        }

        return $entity;
    }

    private function waiting(Proposal $proposal): void
    {
        if ($proposal->status->iri !== Vocab::ASSERTION_STATUSES.'proposed') {
            throw new InvalidArgumentException('this proposal was reviewed already');
        }
    }

    private function note(array $edits): ?string
    {
        return mb_substr(trim((string) ($edits['note'] ?? '')), 0, 4000) ?: null;
    }

    private function concept(string $iri): Concept
    {
        return Concept::where('iri', $iri)->whereNull('deprecated_at')->first()
            ?? throw new InvalidArgumentException("$iri is not a current concept");
    }
}
