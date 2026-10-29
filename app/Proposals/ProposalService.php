<?php

/**
 * ProposalController - Heratio
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * This file is part of Heratio.
 *
 * Heratio is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * OpenRiC Workspace - ported from Heratio, packages/ahg-api/src/Controllers/V2/ProposalController.php
 * at commit 5100c0f92 (heratio-dev; governance/CODE_PROVENANCE.md, ADR 0007): store, queueFields,
 * queueRelationships, index, sources and isoDate. The same payload and answers, so the AHG Archives
 * MCP serves Heratio, PSIS and the Workspace alike. Changed: proposals are rows of one table,
 * their kind and status vocabulary concepts; a proposed relationship is a relation with a proposed
 * assertion by a model for the account (ADR 0006), with the record as its evidence, so both of its
 * agents must exist; names of the record's fields are ISAD(G) elements, and any ISAD(G) element
 * may be named; a field's language is BCP 47; a transcript is of an instantiation of the record;
 * statuses are proposed, accepted and rejected (Heratio's pending and approved are accepted when
 * listing); no authority resolution queue yet (the review queue, ADR 0007 step 3).
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Proposals;

use App\Audit\AuditLog;
use App\Description\Bcp47;
use App\Description\Elements;
use App\Models\Agent;
use App\Models\ApiKey;
use App\Models\Concept;
use App\Models\Instantiation;
use App\Models\Institution;
use App\Models\Place;
use App\Models\Proposal;
use App\Models\RecordResource;
use App\Models\Relation;
use App\Models\RelationAssertion;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Relations\RelationService;
use App\Relations\RelationTypes;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ProposalService
{
    /** Heratio's entity types, as proposal kinds and, for names of agents, agent types. */
    private const TYPES = ['PERSON' => ['agent', 'person'], 'ORG' => ['agent', 'corporate-body'], 'GPE' => ['place', null], 'DATE' => ['date', null]];

    /** Heratio's field names (AtoM's information_object_i18n columns), as ISAD(G) elements. */
    private const FIELDS = ['title' => 'title', 'scope_and_content' => 'scope-and-content', 'archival_history' => 'archival-history',
        'extent_and_medium' => 'extent', 'arrangement' => 'arrangement', 'access_conditions' => 'conditions-governing-access',
        'reproduction_conditions' => 'conditions-governing-reproduction'];

    private const AUTHORITY_FIELDS = ['dates_of_existence' => 255, 'history' => 8000, 'places' => 1000];

    private const MAX_ENTITIES = 50;

    private const MAX_FIELDS = 10;

    private const MAX_TRANSCRIPTS = 5;

    private const MAX_RELATIONSHIPS = 20;

    public const LIST_MAX = 200;

    /** The relation groups whose ends are agents (config workspace.relation_kind_groups). */
    private const AGENT_GROUPS = ['agent', 'person', 'group', 'position'];

    public function __construct(private RelationService $relations) {}

    /**
     * Queue what the payload proposes for the record. Everything is checked before anything is
     * written: a payload with any malformed part is refused whole (ProposalException). What the
     * record already has waiting or accepted is skipped; a relationship whose agents are not
     * both authority records of the archive is answered with an error of its own.
     *
     * @return array{created: bool, body: array<string, mixed>}
     */
    public function propose(RecordResource $record, User $user, ?ApiKey $key, array $input): array
    {
        $list = fn (string $name) => is_array($input[$name] ?? null) ? array_values($input[$name]) : [];
        [$entities, $fields, $transcripts, $relationships] = [$list('entities'), $list('fields'), $list('transcripts'), $list('relationships')];
        if (! $entities && ! $fields && ! $transcripts && ! $relationships) {
            throw new ProposalException('entities (a list of {type, value}), fields (a list of {field, text}), transcripts or relationships is required');
        }
        foreach (['entities' => self::MAX_ENTITIES, 'fields' => self::MAX_FIELDS, 'transcripts' => self::MAX_TRANSCRIPTS, 'relationships' => self::MAX_RELATIONSHIPS] as $name => $max) {
            if (count($list($name)) > $max) {
                throw new ProposalException("At most $max $name per request");
            }
        }
        $label = preg_replace('/[^a-z0-9_-]/i', '', (string) ($input['source'] ?? 'api')) ?: 'api';

        $names = $this->entities($record, $user, $entities);
        $values = [...$this->fields($record, $fields), ...$this->transcripts($record, $transcripts)];
        $relations = $this->relationships($user, $relationships);

        return DB::transaction(fn () => AuditLog::actingAs($user, function () use ($record, $user, $key, $label, $names, $values, $relations) {
            $base = ['institution_id' => $record->institution_id, 'record_resource_id' => $record->id, 'status_id' => $this->concept(Vocab::ASSERTION_STATUSES.'proposed')->id,
                'origin_label' => $label, 'proposed_by_id' => $user->id, 'api_key_id' => $key?->id];
            $queued = [];
            $skipped = 0;
            foreach ($names as $name) {
                if ($this->waiting($record, $name['row'])) {
                    $skipped++;

                    continue;
                }
                Proposal::create([...$base, ...$name['row']]);
                $queued[] = $name['answer'];
            }
            $fieldsQueued = [];
            $fieldsSkipped = 0;
            foreach ($values as $value) {
                if ($this->waiting($record, $value['row'], true)) {
                    $fieldsSkipped++;

                    continue;
                }
                Proposal::create([...$base, ...$value['row']]);
                $fieldsQueued[] = $value['answer'];
            }
            [$relationsQueued, $relationsSkipped] = $this->queueRelationships($record, $user, $label, $relations);

            return ['created' => $queued || $fieldsQueued || array_filter(array_column($relationsQueued, 'queued')), 'body' => [
                'record' => $record->id,
                'queued' => $queued, 'skipped_existing' => $skipped,
                'fields_queued' => $fieldsQueued, 'fields_skipped_existing' => $fieldsSkipped,
                'relationships' => $relationsQueued, 'relationships_skipped_existing' => $relationsSkipped,
            ]];
        }));
    }

    /**
     * The proposals of one account (null: of everyone in the institution), newest first, with
     * what became of them: proposals of names, places, dates, values and transcripts, and
     * relations proposed through a key.
     *
     * @return array{total: int, results: list<array<string, mixed>>}
     */
    public function list(Institution $institution, ?User $of, string $status, int $count, int $start): array
    {
        $status = ['pending' => 'proposed', 'approved' => 'accepted'][$status] ?? $status;
        $items = [];
        // ponytail: the newest thousand of each are read and merged here; page in SQL if anyone proposes more.
        $proposals = Proposal::where('institution_id', $institution->id)->when($of, fn ($q) => $q->where('proposed_by_id', $of->id))
            ->when($status !== '', fn ($q) => $q->whereHas('status', fn ($q) => $q->where('iri', Vocab::ASSERTION_STATUSES.$status)))
            ->with(['kind', 'status', 'element', 'record', 'proposedBy', 'reviewedBy'])->orderByDesc('created_at')->limit(self::LIST_MAX * 5)->get();
        foreach ($proposals as $p) {
            $kind = $p->kind->notation;
            $items[] = [
                'kind' => match ($kind) {
                    'value' => 'field', 'transcript' => 'transcript', default => 'entity'
                },
                'id' => $p->id, 'record' => $p->record_resource_id, 'title' => EntityKinds::label($p->record),
                'type' => match ($kind) {
                    'agent' => array_search([$kind, $p->agentType?->notation], self::TYPES, true) ?: 'PERSON', 'place' => 'GPE', 'date' => 'DATE', default => null
                },
                'field' => $kind === 'value' ? $p->element->notation : ($kind === 'transcript' ? 'transcript' : null),
                'language' => $p->language, 'value' => mb_substr($p->value, 0, 300),
                'linked_to' => $p->linked_id, 'status' => $p->status->notation, 'review_note' => $p->review_note,
                'proposed_by' => $p->proposedBy->name, 'proposed_via' => $p->origin_label,
                'reviewed_by' => $p->reviewedBy?->name, 'reviewed_at' => $p->reviewed_at?->toIso8601String(), 'proposed_at' => $p->created_at->toIso8601String(),
            ];
        }
        $assertions = RelationAssertion::where('institution_id', $institution->id)->whereNotNull('user_id')
            ->whereHas('origin', fn ($q) => $q->where('iri', Vocab::BASE.'assertion-origin/model'))
            ->when($of, fn ($q) => $q->where('user_id', $of->id))
            ->when($status !== '', fn ($q) => $q->whereHas('status', fn ($q) => $q->where('iri', Vocab::ASSERTION_STATUSES.$status)))
            ->with(['status', 'user', 'reviewer', 'relation.relationType', 'relation.evidence.record'])->orderByDesc('asserted_at')->limit(self::LIST_MAX * 5)->get();
        foreach ($assertions as $a) {
            $relation = $a->relation;
            $record = $relation->evidence->first()?->record;
            $items[] = [
                'kind' => 'relationship', 'id' => $a->id, 'record' => $record?->id, 'title' => $record ? EntityKinds::label($record) : null,
                'type' => $relation->kind(),
                'value' => $this->phrase($relation),
                'status' => $a->status->notation, 'review_note' => null,
                'proposed_by' => $a->user->name, 'proposed_via' => $a->origin_label,
                'reviewed_by' => $a->reviewer?->name, 'reviewed_at' => $a->reviewed_at?->toIso8601String(), 'proposed_at' => $a->asserted_at->toIso8601String(),
            ];
        }
        usort($items, fn ($a, $b) => strcmp($b['proposed_at'], $a['proposed_at']));

        return ['total' => count($items), 'results' => array_slice($items, $start, $count)];
    }

    /**
     * Propose text read from a file of the instantiation as its transcript, on behalf of the
     * account that gave the file; null when the same text is waiting already.
     */
    public function proposeTranscript(Instantiation $instantiation, User $user, string $text, string $label, ?string $evidence = null): ?Proposal
    {
        $record = $instantiation->record;
        $row = ['kind_id' => $this->concept(Vocab::PROPOSAL_KINDS.'transcript')->id, 'value' => $text, 'instantiation_id' => $instantiation->id, 'evidence' => $evidence];

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => $this->waiting($record, $row, true) ? null : Proposal::create([...$row,
            'institution_id' => $record->institution_id, 'record_resource_id' => $record->id, 'status_id' => $this->concept(Vocab::ASSERTION_STATUSES.'proposed')->id,
            'origin_label' => $label, 'proposed_by_id' => $user->id])));
    }

    /** @return list<array{row: array<string, mixed>, answer: array<string, mixed>}> */
    private function entities(RecordResource $record, User $user, array $entities): array
    {
        $clean = [];
        foreach ($entities as $i => $e) {
            $e = is_array($e) ? $e : [];
            $type = strtoupper(trim((string) ($e['type'] ?? '')));
            $value = $this->oneLine($e['value'] ?? '');
            if (! isset(self::TYPES[$type]) || $value === '' || mb_strlen($value) > 500) {
                throw new ProposalException("Entity {$i}: type must be one of ".implode(', ', array_keys(self::TYPES)).', value 1-500 characters');
            }
            [$kind, $agentType] = self::TYPES[$type];
            $authority = [];
            if ($kind === 'agent' && is_array($e['authority'] ?? null)) {
                foreach (self::AUTHORITY_FIELDS as $k => $max) {
                    $v = trim((string) ($e['authority'][$k] ?? ''));
                    if ($v !== '') {
                        $authority[$k] = mb_substr($v, 0, $max);
                    }
                }
            }
            // A proposed link to an existing agent or place, checked here so a wrong one fails the request instead of reaching the reviewer.
            $linkKey = (string) ($kind === 'place' ? ($e['place_id'] ?? $e['term_id'] ?? '') : ($e['agent_id'] ?? $e['actor_id'] ?? ''));
            $link = null;
            if ($linkKey !== '') {
                if ($kind === 'date') {
                    throw new ProposalException("Entity {$i}: a date cannot link to an authority record");
                }
                $link = $this->existing($kind === 'place' ? Place::class : Agent::class, $linkKey, $record->institution_id, $user)
                    ?? throw new ProposalException("Entity {$i}: ".($kind === 'place' ? 'place_id is not a place' : 'agent_id is not an authority record').' of this archive');
            }
            // The first of the same name in a request is kept.
            $clean[$type."\0".mb_strtolower($value)] ??= [
                'row' => [
                    'kind_id' => $this->concept(Vocab::PROPOSAL_KINDS.$kind)->id, 'value' => $value,
                    'agent_type_id' => $agentType ? $this->concept(Agent::TYPES.$agentType)->id : null,
                    'linked_table' => $link?->getTable(), 'linked_id' => $link?->getKey(),
                    'link_reason' => $link ? (mb_substr(trim((string) ($e['link_reason'] ?? '')), 0, 1000) ?: null) : null,
                    'authority' => $authority ?: null,
                    'confidence' => isset($e['confidence']) && is_numeric($e['confidence']) ? max(0.0, min(1.0, (float) $e['confidence'])) : null,
                    'evidence' => $this->evidence($e), 'sources' => self::sources($e['sources'] ?? []),
                ],
                'answer' => ['type' => $type, 'value' => $value] + ($link ? ['linked_to' => $link->getKey()] : []),
            ];
        }

        return array_values($clean);
    }

    /** @return list<array{row: array<string, mixed>, answer: array<string, mixed>}> */
    private function fields(RecordResource $record, array $fields): array
    {
        $elements = Elements::notations(Elements::ISAD_G);
        $rows = [];
        foreach ($fields as $i => $f) {
            $f = is_array($f) ? $f : [];
            $field = (string) ($f['field'] ?? '');
            $notation = self::FIELDS[$field] ?? (in_array($field, $elements, true) ? $field : null);
            $text = trim((string) ($f['text'] ?? ''));
            if ($notation === null || $text === '' || mb_strlen($text) > ($notation === 'title' ? 1024 : 20000)) {
                throw new ProposalException("Field {$i}: field must be an ISAD(G) element of the description-element vocabulary, such as scope-and-content, or one of "
                    .implode(', ', array_keys(self::FIELDS)).'; text 1-20000 characters (title 1-1024)');
            }
            // A language other than the record's makes it a translation into that language.
            $language = str_replace('_', '-', trim((string) ($f['language'] ?? $f['culture'] ?? '')));
            if ($language !== '' && ! Bcp47::valid($language)) {
                throw new ProposalException("Field {$i}: language must be a BCP 47 language tag such as en, af or pt-BR");
            }
            $original = $record->originalLanguage('title');
            $language = $language ?: $original;
            $rows[] = [
                'row' => ['kind_id' => $this->concept(Vocab::PROPOSAL_KINDS.'value')->id, 'value' => $text,
                    'element_id' => $this->concept(Vocab::element($notation))->id, 'language' => $language,
                    'evidence' => $this->evidence($f), 'sources' => self::sources($f['sources'] ?? [])],
                'answer' => ['field' => $notation] + ($language !== $original ? ['language' => $language] : []),
            ];
        }

        return $rows;
    }

    /** @return list<array{row: array<string, mixed>, answer: array<string, mixed>}> */
    private function transcripts(RecordResource $record, array $transcripts): array
    {
        $rows = [];
        foreach ($transcripts as $i => $t) {
            $t = is_array($t) ? $t : [];
            $text = trim((string) ($t['text'] ?? ''));
            if ($text === '' || strlen($text) > 65000) {
                throw new ProposalException("Transcript {$i}: text is required, at most 65,000 bytes; propose a long document page by page");
            }
            $named = (string) ($t['instantiation_id'] ?? $t['digital_object_id'] ?? '');
            $instantiation = Instantiation::where('record_resource_id', $record->id)->whereNull('deleted_at')
                ->when($named !== '', fn ($q) => $q->whereKey($this->uuid($named) ? $named : null))
                ->orderBy('position')->orderBy('id')->first();
            if ($instantiation === null) {
                throw new ProposalException("Transcript {$i}: ".($named === '' ? 'this record has no instantiation to transcribe' : 'instantiation_id is not an instantiation of this record'));
            }
            $rows[] = [
                'row' => ['kind_id' => $this->concept(Vocab::PROPOSAL_KINDS.'transcript')->id, 'value' => $text, 'instantiation_id' => $instantiation->id,
                    'evidence' => $this->evidence($t), 'sources' => self::sources($t['sources'] ?? [])],
                'answer' => ['field' => 'transcript', 'instantiation_id' => $instantiation->id],
            ];
        }

        return $rows;
    }

    /**
     * Check each relationship: its type, and agents that exist when named by key. A side
     * named only by its name is left for queueRelationships to answer.
     *
     * @return list<array<string, mixed>>
     */
    private function relationships(User $user, array $relationships): array
    {
        $rows = [];
        foreach ($relationships as $i => $r) {
            $r = is_array($r) ? $r : [];
            [$type, $inverse] = $this->relationType((string) ($r['relation'] ?? ''))
                ?? throw new ProposalException("Relationship {$i}: relation must be a relation type between agents, by its notation or how it reads, such as spouse or \"is or was married to\": "
                    .implode(', ', array_keys($this->agentRelationTypes())));
            $row = ['type' => $type];
            foreach (['subject', 'object'] as $side) {
                $key = (string) ($r[$side.'_agent_id'] ?? $r[$side.'_actor_id'] ?? '');
                $name = $this->oneLine($r[$side.'_name'] ?? '');
                $agent = null;
                if ($key !== '') {
                    $agent = $this->existing(Agent::class, $key, $user->institution_id, $user)
                        ?? throw new ProposalException("Relationship {$i}: {$side}_agent_id is not an authority record of this archive");
                    $name = EntityKinds::label($agent);
                }
                if ($name === '' || mb_strlen($name) > 500) {
                    throw new ProposalException("Relationship {$i}: {$side}_name (1-500 characters) or {$side}_agent_id is required");
                }
                $row[$side] = $agent;
                $row[$side.'_name'] = $name;
            }
            if ($inverse) {
                [$row['subject'], $row['object'], $row['subject_name'], $row['object_name']] = [$row['object'], $row['subject'], $row['object_name'], $row['subject_name']];
            }
            foreach (['start_date' => 'from', 'end_date' => 'to'] as $given => $field) {
                $day = trim((string) ($r[$given] ?? ''));
                if ($day !== '' && self::isoDate($day) === null) {
                    throw new ProposalException("Relationship {$i}: {$given} must be a date such as 1901-05-31");
                }
                $row[$field] = $day === '' ? null : $day;
            }
            $language = str_replace('_', '-', trim((string) ($r['language'] ?? '')));
            if ($language !== '' && ! Bcp47::valid($language)) {
                throw new ProposalException("Relationship {$i}: language must be a BCP 47 language tag such as en");
            }
            // The evidence, sources and date as said become the basis of the assertion.
            $basis = array_filter([
                $this->evidence($r),
                ($date = mb_substr(trim((string) ($r['date'] ?? '')), 0, 255)) !== '' ? 'Date: '.$date : null,
                ($sources = self::sources($r['sources'] ?? [])) ? 'Sources: '.implode(' ', $sources) : null,
            ]);
            $row['basis'] = $basis ? implode("\n\n", $basis) : null;
            $row['basis_language'] = $basis ? ($language ?: 'und') : null;
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} */
    private function queueRelationships(RecordResource $record, User $user, string $label, array $rows): array
    {
        $queued = [];
        $skipped = 0;
        foreach ($rows as $row) {
            $answer = ['subject' => $row['subject_name'], 'relation' => $row['type'], 'object' => $row['object_name']];
            foreach (['subject', 'object'] as $side) {
                if ($row[$side] === null) {
                    $queued[] = $answer + ['queued' => false, 'error' => "\"{$row[$side.'_name']}\" is not an authority record of this archive ({$side}_agent_id): "
                        .'propose it as a person or organisation (entities), and the relationship again once an archivist has accepted it'];

                    continue 2;
                }
            }
            if ($this->related($row['subject'], $row['type'], $row['object'])) {
                $skipped++;

                continue;
            }
            try {
                $relation = $this->relations->create($row['subject'], $row['type'], $row['object'], ['from' => $row['from'], 'to' => $row['to']], $user,
                    ['origin' => 'model', 'label' => $label, 'user' => $user, 'basis' => $row['basis'], 'basis_language' => $row['basis_language']]);
            } catch (InvalidArgumentException $e) {
                // Such as a spouse who is an organisation: this one is refused, the rest go on.
                $queued[] = $answer + ['queued' => false, 'error' => $e->getMessage()];

                continue;
            }
            $this->relations->addEvidence($relation, $record, $user);
            $queued[] = $answer + ['queued' => true, 'id' => $relation->assertions()->value('id')];
        }

        return [$queued, $skipped];
    }

    /** Does the record have the same proposal waiting, or accepted? Values and transcripts: only the same text waiting. */
    private function waiting(RecordResource $record, array $row, bool $text = false): bool
    {
        $statuses = $text ? ['proposed'] : ['proposed', 'accepted'];

        return Proposal::where('record_resource_id', $record->id)->where('kind_id', $row['kind_id'])
            ->whereHas('status', fn ($q) => $q->whereIn('iri', array_map(fn ($s) => Vocab::ASSERTION_STATUSES.$s, $statuses)))
            ->when($text, fn ($q) => $q->where('value', $row['value']), fn ($q) => $q->whereRaw('lower(value) = ?', [mb_strtolower($row['value'])]))
            ->exists();
    }

    /** Is there a relation of the type between the two agents (either way round, if it is symmetric) that is not rejected? */
    private function related(Model $subject, string $type, Model $object): bool
    {
        $symmetric = (bool) (RelationTypes::get($type, null)['symmetric'] ?? false);
        $ends = fn ($q, Model $from, Model $to) => $q->where('source_table', $from->getTable())->where('source_id', $from->getKey())
            ->where('target_table', $to->getTable())->where('target_id', $to->getKey());

        return Relation::whereHas('relationType', fn ($q) => $q->where('iri', Vocab::BASE.'relation-type/'.$type))
            ->where(fn ($q) => $q->where(fn ($q) => $ends($q, $subject, $object))->when($symmetric, fn ($q) => $q->orWhere(fn ($q) => $ends($q, $object, $subject))))
            ->with('assertions.status')->get()->contains(fn (Relation $r) => $r->standing() !== 'rejected');
    }

    /**
     * A relation type between agents, by its notation or by how it reads from its source
     * (forward) or its target (inverse, which turns the two round); null if there is none.
     *
     * @return array{0: string, 1: bool}|null
     */
    private function relationType(string $given): ?array
    {
        $given = mb_strtolower($this->oneLine($given));
        foreach ($this->agentRelationTypes() as $notation => $definition) {
            if ($given === $notation || $given === mb_strtolower($definition['forward'])) {
                return [$notation, false];
            }
            if ($given === mb_strtolower($definition['inverse'])) {
                return [$notation, empty($definition['symmetric'])];
            }
        }

        return null;
    }

    /** @return array<string, array<string, mixed>> the relation types whose two ends are always agents */
    private function agentRelationTypes(): array
    {
        $agentEnd = fn (string $end) => in_array(strtok($end, ':'), self::AGENT_GROUPS, true);

        return array_filter(RelationTypes::all(null),
            fn (array $definition) => collect($definition['pairs'])->every(fn (array $pair) => $agentEnd($pair[0]) && $agentEnd($pair[1])));
    }

    /** How the relation reads, between its two ends' names. */
    private function phrase(Relation $relation): string
    {
        $name = fn (string $table, string $id) => ($entity = EntityKinds::get((string) EntityKinds::byTable($table))['class']::find($id)) ? EntityKinds::label($entity) : $id;
        $type = RelationTypes::of($relation);

        return $name($relation->source_table, $relation->source_id).' '.($type['forward'] ?? $relation->relationType->notation).' '.$name($relation->target_table, $relation->target_id);
    }

    /** An agent or place of the institution, not deleted, that the account may read; null for anything else. */
    private function existing(string $class, string $key, string $institutionId, User $user): ?Model
    {
        $entity = $this->uuid($key) ? $class::where('institution_id', $institutionId)->whereNull('deleted_at')->find($key) : null;

        return $entity && EntityKinds::readable($user, $entity) ? $entity : null;
    }

    private function uuid(string $key): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $key) === 1;
    }

    private function oneLine(mixed $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }

    private function evidence(array $item): ?string
    {
        return mb_substr(trim((string) ($item['evidence'] ?? '')), 0, 4000) ?: null;
    }

    private static function isoDate(string $v): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4)) ? $v : null;
    }

    /** At most ten http(s) web addresses of up to 500 characters. */
    private static function sources(mixed $list): array
    {
        $out = [];
        foreach (array_slice(is_array($list) ? $list : [], 0, 10) as $u) {
            $u = trim((string) $u);
            if (mb_strlen($u) <= 500 && preg_match('#^https?://#i', $u)) {
                $out[] = $u;
            }
        }

        return $out;
    }

    private function concept(string $iri): Concept
    {
        return Concept::where('iri', $iri)->whereNull('deprecated_at')->first()
            ?? throw new InvalidArgumentException("$iri is not a current concept");
    }
}
