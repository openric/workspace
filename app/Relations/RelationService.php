<?php

// OpenRiC Workspace - relate entities, prove relations and assert them (ADR 0006, WS-006).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A relation is created with its first assertion. A person's assertion is
// accepted as made; one by an import or a model is proposed, and becomes
// authoritative only when a person accepts it. Later assertions corroborate
// or dispute a relation, or supersede an earlier one; none is ever changed,
// so the history of who said what stays whole.

namespace App\Relations;

use App\Audit\AuditLog;
use App\Description\Bcp47;
use App\Description\DateService;
use App\Description\StorageService;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\Name;
use App\Models\RecordResource;
use App\Models\Relation;
use App\Models\RelationAssertion;
use App\Models\RelationEvidence;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RelationService
{
    private const TYPES = Vocab::BASE.'relation-type/';

    private const ROLES = 'https://openric.org/vocab/role-type/';

    public function __construct(private DateService $dates) {}

    /**
     * Relate $source to $target. Fields: role, state, certainty (notations),
     * source_of_information, description, language, from, to (EDTF).
     * The first assertion is the actor's, or the named import's or model's; a model's may
     * also name the account it proposed for (user), as a proposal through an API key does.
     *
     * @param  array{origin?: string, label?: ?string, basis?: ?string, basis_language?: ?string, user?: ?User}  $assertion
     */
    public function create(Model $source, string $type, Model $target, array $fields, ?User $actor = null, array $assertion = []): Relation
    {
        $definition = $this->definition($type);
        $this->allowedPair($definition, $type, $source, $target);
        if ($type === 'location') {
            $storage = app(StorageService::class);
            if (! $storage->isStoragePlace($target)) {
                throw new InvalidArgumentException('a container is kept in a storage place: a place of a storage place type');
            }
            // Only one place keeps a container at a time; a move ends where it was first (StorageService::move).
            if (trim((string) ($fields['to'] ?? '')) === '' && $storage->currentLocation($source) !== null) {
                throw new InvalidArgumentException('the container is kept somewhere already: move it, which ends where it was kept');
            }
        }
        foreach ([$source, $target] as $end) {
            if ($end instanceof Concept && ! in_array($end->vocabulary?->kind?->notation, $definition['schemes'] ?? [], true)) {
                throw new InvalidArgumentException("a $type relation does not connect a concept of that vocabulary");
            }
            if ($end instanceof Concept && $end->deprecated_at !== null) {
                throw new InvalidArgumentException('a deprecated concept is not used for new relations');
            }
        }
        if ($source->getAttribute('institution_id') !== $target->getAttribute('institution_id')) {
            throw new InvalidArgumentException('a relation connects entities of one institution');
        }
        $values = $this->values($definition, $fields);

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($source, $type, $target, $fields, $values, $actor, $assertion) {
            $institution = Institution::findOrFail($source->getAttribute('institution_id'));
            $id = (string) Str::uuid7();
            $relation = new Relation([...$values, 'institution_id' => $institution->id, 'iri' => $institution->base_iri.'/'.Relation::IRI_SEGMENT.'/'.$id,
                'relation_type_id' => $this->concept(self::TYPES.$type)->id,
                'source_table' => $source->getTable(), 'source_id' => $source->getKey(), 'target_table' => $target->getTable(), 'target_id' => $target->getKey()]);
            $relation->id = $id;
            $relation->save();
            $this->setDates($relation, $fields);
            $origin = $assertion['origin'] ?? 'account';
            $this->addAssertion($relation, $origin, $origin === 'account' ? $actor : ($assertion['user'] ?? null), $assertion['label'] ?? null,
                $assertion['basis'] ?? null, $assertion['basis_language'] ?? null, $origin === 'account' ? 'accepted' : 'proposed');

            return $relation;
        }));
    }

    /** Change what is said about the relation; what it connects and its type do not change (remove it and relate again). */
    public function update(Relation $relation, array $fields, ?User $actor = null): void
    {
        $values = $this->values($this->definition($relation->relationType->notation), $fields);
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($relation, $values, $fields) {
            $relation->update($values);
            $this->setDates($relation, $fields);
        }));
    }

    /** End the relation on a day (EDTF), keeping everything else said about it, as a move ends where a container was kept. */
    public function end(Relation $relation, string $day, ?User $actor = null): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $this->setDates($relation, ['to' => $day])));
    }

    /** Remove a relation made in error, with its dates, evidence and assertions; the audit log keeps every row. */
    public function remove(Relation $relation, ?User $actor = null): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($relation) {
            $relation->evidence()->get()->each->delete();
            $relation->dates()->get()->each->delete();
            // Newest first: a superseded assertion is referred to by the one after it.
            $relation->assertions()->get()->sortByDesc(fn (RelationAssertion $a) => [$a->asserted_at, $a->id])->each->delete();
            $relation->delete();
        }));
    }

    /**
     * A person asserts the relation holds ($holds) or does not, on a basis, optionally
     * superseding an earlier assertion of the relation.
     */
    public function assert(Relation $relation, User $actor, bool $holds, ?string $basis, ?string $basisLanguage, ?RelationAssertion $supersedes = null): RelationAssertion
    {
        if ($supersedes && $supersedes->relation_id !== $relation->id) {
            throw new InvalidArgumentException('an assertion can only supersede one about the same relation');
        }

        return $this->guarded(fn () => AuditLog::actingAs($actor, fn () => $this->addAssertion($relation, 'account', $actor, null, $basis, $basisLanguage, $holds ? 'accepted' : 'rejected', $supersedes)));
    }

    /** A person accepts or rejects a proposed assertion. */
    public function review(RelationAssertion $assertion, bool $accept, User $actor): void
    {
        if ($assertion->status->iri !== Vocab::BASE.'assertion-status/proposed') {
            throw new InvalidArgumentException('only a proposed assertion is reviewed; assert again to change your mind');
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $assertion->update([
            'status_id' => $this->concept(Vocab::BASE.'assertion-status/'.($accept ? 'accepted' : 'rejected'))->id,
            'reviewed_by_id' => $actor->id, 'reviewed_at' => now(),
        ])));
    }

    public function addEvidence(Relation $relation, RecordResource $record, ?User $actor = null): RelationEvidence
    {
        return $this->guarded(fn () => AuditLog::actingAs($actor, fn () => RelationEvidence::create([
            'institution_id' => $relation->institution_id, 'relation_id' => $relation->id, 'record_resource_id' => $record->id,
        ])), 'that unit of description is already evidence of this relation');
    }

    public function removeEvidence(RelationEvidence $evidence, ?User $actor = null): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $evidence->delete()));
    }

    /**
     * Every relation of an entity, as source or target, with the entity at the other end
     * and what the page shows loaded.
     *
     * @return Collection<int, Relation>
     */
    public function of(Model $entity): Collection
    {
        $relations = Relation::where(fn ($q) => $q->where(['source_table' => $entity->getTable(), 'source_id' => $entity->getKey()])
            ->orWhere(fn ($q) => $q->where(['target_table' => $entity->getTable(), 'target_id' => $entity->getKey()])))
            ->with('relationType.labels', 'role.labels', 'state.labels', 'certainty.labels', 'dates.property', 'evidence.record.names.nameType',
                'assertions.origin.labels', 'assertions.status', 'assertions.user', 'assertions.reviewer')
            ->orderBy('created_at')->get();
        $ends = [];
        foreach ($relations as $relation) {
            $relation->fromSource = $relation->source_table === $entity->getTable() && $relation->source_id === $entity->getKey();
            [$table, $id] = $relation->fromSource ? [$relation->target_table, $relation->target_id] : [$relation->source_table, $relation->source_id];
            $ends[$table][] = $id;
        }
        $loaded = [];
        foreach ($ends as $table => $ids) {
            $definition = EntityKinds::get(EntityKinds::byTable($table));
            $loaded[$table] = $definition['class']::whereKey(array_unique($ids))->with($definition['with'])->get()->keyBy('id');
        }
        foreach ($relations as $relation) {
            $relation->other = $relation->fromSource ? $loaded[$relation->target_table][$relation->target_id] : $loaded[$relation->source_table][$relation->source_id];
        }

        return $relations;
    }

    /**
     * The relations the entity can take part in: for each type, the side the entity is on
     * and the kinds at the other end.
     *
     * @return list<array{type: string, side: string, kinds: list<string>, schemes: list<string>, phrase: string}>
     */
    public function options(Model $entity): array
    {
        $options = [];
        foreach (config('workspace.relation_types') as $type => $definition) {
            foreach (['source', 'target'] as $side) {
                if ($side === 'target' && ($definition['symmetric'] ?? false)) {
                    continue;
                }
                $kinds = [];
                foreach ($definition['pairs'] as [$sourceKind, $targetKind]) {
                    [$mine, $theirs] = $side === 'source' ? [$sourceKind, $targetKind] : [$targetKind, $sourceKind];
                    if (EntityKinds::matchesAny($entity, EntityKinds::expand($mine))) {
                        $kinds = [...$kinds, ...EntityKinds::expand($theirs)];
                    }
                }
                if ($kinds !== []) {
                    $options[] = ['type' => $type, 'side' => $side, 'kinds' => array_values(array_unique($kinds)), 'schemes' => $definition['schemes'] ?? [],
                        'phrase' => $side === 'source' ? $definition['forward'] : $definition['inverse']];
                }
            }
        }

        return $options;
    }

    /**
     * Entities of the given kinds (or kind:types) whose names, labels or local identifier contain $query,
     * that the account may read, other than $except; a concept only from a vocabulary of
     * one of the $schemes kinds; at most 25.
     *
     * @return Collection<int, Model>
     */
    /** The entities of these kinds a relation may reach; a storage location reaches only storage places, any other relation only the other places. */
    public function candidates(User $user, array $kinds, string $query, ?Model $except = null, array $schemes = [], ?string $type = null): Collection
    {
        $query = trim($query);
        $like = '%'.addcslashes($query, '%_\\').'%';
        $found = collect();
        foreach ($kinds as $spec) {
            $kind = strtok($spec, ':');
            $definition = EntityKinds::get($kind);
            $table = (new $definition['class'])->getTable();
            $search = EntityKinds::existing($spec, $user->institution_id)
                ->when($except, fn ($q) => $q->whereKeyNot($except->getKey()))
                ->with($definition['with'])->limit(25);
            if ($kind === 'place') {
                app(StorageService::class)->scopeStorage($search, $user->institution_id, $type === 'location');
            }
            if ($kind === 'concept') {
                $search->whereHas('vocabulary.kind', fn ($q) => $q->whereIn('notation', $schemes))
                    ->when($query !== '', fn ($q) => $q->where(fn ($q) => $q
                        ->whereIn('id', DB::table('concept_labels')->where('pref_label', 'ilike', $like)->select('concept_id'))
                        ->orWhereIn('id', DB::table('concept_alt_labels')->where('label', 'ilike', $like)->select('concept_id'))
                        ->orWhere('notation', 'ilike', $like)));
            } else {
                $search->when($query !== '', fn ($q) => $q->where(fn ($q) => $q
                    ->whereIn('id', Name::where('subject_table', $table)->where('value', 'ilike', $like)->select('subject_id'))
                    ->orWhereIn('id', DB::table('identifiers')->where('subject_table', $table)->where('value', 'ilike', $like)->select('subject_id'))));
            }
            $found = $found->merge($search->get()->filter(fn (Model $m) => EntityKinds::readable($user, $m)));
        }

        return $found->take(25)->values();
    }

    private function addAssertion(Relation $relation, string $origin, ?User $user, ?string $label, ?string $basis, ?string $basisLanguage, string $status, ?RelationAssertion $supersedes = null): RelationAssertion
    {
        $basis = trim((string) $basis);
        if ($basis !== '' && ! Bcp47::valid((string) $basisLanguage)) {
            throw new InvalidArgumentException('give the language of the basis as a BCP 47 tag, such as en or af');
        }
        if ($user === null && trim((string) $label) === '') {
            throw new InvalidArgumentException('an assertion by an import or a model names it');
        }
        $institution = Institution::findOrFail($relation->institution_id);
        $id = (string) Str::uuid7();
        $assertion = new RelationAssertion(['institution_id' => $institution->id, 'iri' => $institution->base_iri.'/'.RelationAssertion::IRI_SEGMENT.'/'.$id,
            'relation_id' => $relation->id, 'origin_id' => $this->concept(Vocab::BASE.'assertion-origin/'.$origin)->id,
            'user_id' => $user?->id, 'origin_label' => $origin === 'account' ? null : trim((string) $label),
            'basis' => $basis === '' ? null : $basis, 'basis_language' => $basis === '' ? null : $basisLanguage,
            'asserted_at' => now(), 'status_id' => $this->concept(Vocab::BASE.'assertion-status/'.$status)->id, 'supersedes_id' => $supersedes?->id]);
        $assertion->id = $id;
        $assertion->save();
        $relation->unsetRelation('assertions');

        return $assertion;
    }

    private function definition(string $type): array
    {
        return config('workspace.relation_types.'.$type) ?? throw new InvalidArgumentException("$type is not a relation type");
    }

    private function allowedPair(array $definition, string $type, Model $source, Model $target): void
    {
        foreach ($definition['pairs'] as [$s, $t]) {
            if (EntityKinds::matchesAny($source, EntityKinds::expand($s)) && EntityKinds::matchesAny($target, EntityKinds::expand($t))) {
                return;
            }
        }
        throw new InvalidArgumentException("a $type relation does not connect a ".EntityKinds::noun($source).' to a '.EntityKinds::noun($target));
    }

    private function values(array $definition, array $fields): array
    {
        $text = fn (string $key) => ($v = trim((string) ($fields[$key] ?? ''))) === '' ? null : $v;
        $source = $text('source_of_information');
        $description = $text('description');
        $language = $text('language');
        if (($source !== null || $description !== null) && ! Bcp47::valid((string) $language)) {
            throw new InvalidArgumentException('give the language of the source and description as a BCP 47 tag, such as en or af');
        }
        $role = $text('role');
        if ($role !== null && ! ($definition['role'] ?? false)) {
            throw new InvalidArgumentException('this kind of relation has no agent role');
        }
        $concept = fn (?string $notation, string $vocabulary) => $notation === null ? null : $this->concept($vocabulary.$notation)->id;

        return [
            'role_id' => $concept($role, self::ROLES),
            'state_id' => $concept($text('state'), Vocab::BASE.'relation-state/'),
            'certainty_id' => $concept($text('certainty'), Vocab::BASE.'relation-certainty/'),
            'source_of_information' => $source, 'description' => $description,
            'language' => ($source !== null || $description !== null) ? $language : null,
        ];
    }

    /** from and to are EDTF: the relation's beginning and end (rico:hasBeginningDate, rico:hasEndDate); empty removes one. */
    private function setDates(Relation $relation, array $fields): void
    {
        foreach (['from' => 'beginning', 'to' => 'end'] as $field => $property) {
            if (! array_key_exists($field, $fields)) {
                continue;
            }
            $value = trim((string) $fields[$field]);
            $current = $relation->dates()->whereHas('property', fn ($q) => $q->where('iri', Vocab::BASE.'date-property/'.$property))->first();
            if ($value === '') {
                $current?->delete();
            } elseif ($current === null) {
                $this->dates->add($relation, ['property' => $property, 'normalized' => $value]);
            } elseif ($current->normalized_value !== $value) {
                $this->dates->update($current, ['property' => $property, 'normalized' => $value, 'expressed' => $current->expressed, 'language' => $current->expressed_language]);
            }
        }
        $relation->unsetRelation('dates');
    }

    private function guarded(callable $write, string $message = 'that assertion was already superseded'): mixed
    {
        try {
            return DB::transaction($write);
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException($message);
        }
    }

    private function concept(string $iri): Concept
    {
        return Concept::where('iri', $iri)->whereNull('deprecated_at')->first()
            ?? throw new InvalidArgumentException("$iri is not a current concept");
    }
}
