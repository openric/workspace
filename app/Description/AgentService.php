<?php

// OpenRiC Workspace - authority records: create, describe, control, merge, split, find duplicates (ISAAR(CPF); ADR 0006).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The functions of Heratio's ahg-actor-manage (add, edit, merge, split,
// duplicate detection with dismissal, completeness, the publication embargo)
// were read from it; the code is the Workspace's. An agent's names are
// rico:AgentName entities and its relations are reified, so merging and
// splitting move names and relations, never text. A merged duplicate is
// marked deleted and names the agent kept, so its IRI leads there.

namespace App\Description;

use App\Audit\AuditLog;
use App\Models\Agent;
use App\Models\Concept;
use App\Models\ContactPoint;
use App\Models\Identifier;
use App\Models\Institution;
use App\Models\Name;
use App\Models\Relation;
use App\Models\Repository;
use App\Models\User;
use App\Relations\RelationService;
use App\Support\Vocab;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AgentService
{
    /** The ISAAR(CPF) essential elements (ISAAR(CPF) 4.8), checked by completeness(). */
    public const ESSENTIAL = ['type of entity (5.1.1)', 'authorised form of name (5.1.2)', 'dates of existence (5.2.1)', 'description identifier (5.4.1)'];

    public function __construct(private DescriptionService $descriptions, private IdentifierService $identifiers, private RelationService $relations) {}

    public function create(Institution $institution, string $type, string $name, string $language, ?string $identifier = null, ?User $actor = null): Agent
    {
        $typeConcept = $this->concept(Agent::TYPES.$type);

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($institution, $typeConcept, $name, $language, $identifier) {
            $id = (string) Str::uuid7();
            $agent = new Agent(['institution_id' => $institution->id, 'iri' => $institution->base_iri.'/'.Agent::IRI_SEGMENT.'/'.$id, 'agent_type_id' => $typeConcept->id]);
            $agent->id = $id;
            $agent->save();
            $this->describe($agent, ['agent-authorised-name' => $name], $language);
            $this->identifiers->setLocal($agent, $identifier);

            return $agent;
        }));
    }

    /** @param  array<string, ?string>  $values  ISAAR(CPF) element notation => text, in one language */
    public function describe(Agent $agent, array $values, string $language, ?User $actor = null): void
    {
        $this->descriptions->describe($agent, Elements::ISAAR_CPF, $values, $language, 'agent-authorised-name', $actor);
    }

    /**
     * Type of entity (5.1.1), identifier (5.1.6), description identifier (5.4.1), the repository
     * that maintains it, status (5.4.4), level of detail (5.4.5) and the embargo date. A type
     * changes only while no relation or date depends on it.
     *
     * @param  array{type: string, identifier?: ?string, description_identifier?: ?string, maintainer?: ?string, status?: ?string, detail?: ?string, embargo_until?: ?string}  $fields
     */
    public function updateControl(Agent $agent, array $fields, ?User $actor = null): void
    {
        $type = $this->concept(Agent::TYPES.($fields['type'] ?? ''));
        $maintainer = null;
        if (($fields['maintainer'] ?? '') !== '' && ($fields['maintainer'] ?? null) !== null) {
            $maintainer = Repository::where(['id' => $fields['maintainer'], 'institution_id' => $agent->institution_id])->whereNull('deleted_at')->first()
                ?? throw new InvalidArgumentException('the authority record is maintained by a repository of the institution');
        }
        $embargo = trim((string) ($fields['embargo_until'] ?? ''));
        if ($embargo !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $embargo)) {
            throw new InvalidArgumentException('give the embargo date as YYYY-MM-DD');
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($agent, $fields, $type, $maintainer, $embargo) {
            if ($type->id !== $agent->agent_type_id) {
                $this->typeMayChange($agent, $type);
            }
            $text = fn (string $key) => ($v = trim((string) ($fields[$key] ?? ''))) === '' ? null : $v;
            $agent->update([
                'agent_type_id' => $type->id,
                'description_identifier' => $text('description_identifier'),
                'maintainer_id' => $maintainer?->id,
                'description_status_id' => $text('status') ? $this->concept($text('status'))->id : null,
                'description_detail_id' => $text('detail') ? $this->concept($text('detail'))->id : null,
                'embargo_until' => $embargo === '' ? null : $embargo,
            ]);
            $agent->unsetRelation('agentType');
            if (array_key_exists('identifier', $fields)) {
                $this->identifiers->setLocal($agent, $fields['identifier']);
            }
        }));
    }

    /**
     * Merge a duplicate into the agent kept. Its relations move to the kept agent (one the kept
     * agent already has is not made twice); its names become other forms of name there, unless
     * the kept agent has that name already; its identifiers move, a local one as a previous
     * identifier; its contact points move. It is marked deleted and names the agent kept.
     */
    public function merge(Agent $duplicate, Agent $kept, ?User $actor = null): void
    {
        if ($duplicate->id === $kept->id || $duplicate->institution_id !== $kept->institution_id || $duplicate->deleted_at || $kept->deleted_at) {
            throw new InvalidArgumentException('an agent merges into another current agent of the institution');
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($duplicate, $kept, $actor) {
            foreach (['source', 'target', 'context'] as $side) {
                foreach (Relation::where([$side.'_table' => 'agents', $side.'_id' => $duplicate->id])->get() as $relation) {
                    $moved = [$side.'_id' => $kept->id];
                    $ends = array_replace($relation->only(['relation_type_id', 'source_table', 'source_id', 'target_table', 'target_id']), $side === 'context' ? [] : $moved);
                    $twin = $side !== 'context' && Relation::where($ends)->whereKeyNot($relation->id)->exists();
                    if ($twin || $this->wouldBeReflexive($relation, $side, $kept)) {
                        $this->relations->remove($relation, $actor);
                    } else {
                        $relation->update($moved);
                    }
                }
            }
            $other = $this->concept(Vocab::NAME_TYPES.'other')->id;
            $keptNames = Name::where(['subject_table' => 'agents', 'subject_id' => $kept->id])->get()->map(fn ($n) => $n->language.'|'.$n->value)->all();
            foreach (Name::where(['subject_table' => 'agents', 'subject_id' => $duplicate->id])->get() as $name) {
                if (in_array($name->language.'|'.$name->value, $keptNames, true)) {
                    continue;
                }
                $name->update(['subject_id' => $kept->id, 'name_type_id' => $name->nameType->notation === 'authorised' ? $other : $name->name_type_id, 'is_original' => false]);
            }
            $local = $this->concept(Vocab::IDENTIFIER_TYPES.'local')->id;
            $previous = $this->concept(Vocab::IDENTIFIER_TYPES.'previous')->id;
            foreach (Identifier::where(['subject_table' => 'agents', 'subject_id' => $duplicate->id])->get() as $identifier) {
                $type = $identifier->identifier_type_id === $local ? $previous : $identifier->identifier_type_id;
                Identifier::where(['subject_table' => 'agents', 'subject_id' => $kept->id, 'identifier_type_id' => $type, 'value' => $identifier->value])->exists()
                    ? $identifier->delete() : $identifier->update(['subject_id' => $kept->id, 'identifier_type_id' => $type]);
            }
            ContactPoint::where(['subject_table' => 'agents', 'subject_id' => $duplicate->id])->get()
                ->each(fn (ContactPoint $c) => $c->update(['subject_id' => $kept->id, 'is_primary' => false]));
            $duplicate->forceFill(['deleted_at' => now(), 'merged_into_id' => $kept->id])->save();
        }));
    }

    /**
     * Split an agent: a new agent of the given type takes the chosen names (the first becomes its
     * authorised name if none of them is one) and the chosen relations. The agent split keeps an
     * authorised name.
     *
     * @param  list<string>  $nameIds
     * @param  list<string>  $relationIds
     */
    public function split(Agent $agent, string $type, array $nameIds, array $relationIds, ?User $actor = null): Agent
    {
        $names = Name::where(['subject_table' => 'agents', 'subject_id' => $agent->id])->whereKey($nameIds)->with('nameType')->orderBy('created_at')->get();
        if ($names->isEmpty() || $names->count() !== count(array_unique($nameIds))) {
            throw new InvalidArgumentException('choose at least one of the agent\'s names for the new agent');
        }
        $authorised = $this->concept(Vocab::NAME_TYPES.'authorised')->id;
        $staying = Name::where(['subject_table' => 'agents', 'subject_id' => $agent->id, 'name_type_id' => $authorised])->whereKeyNot($names->pluck('id'))->exists();
        if (! $staying) {
            throw new InvalidArgumentException('the agent keeps an authorised form of name; leave one of its authorised names with it');
        }
        $typeConcept = $this->concept(Agent::TYPES.$type);

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($agent, $names, $relationIds, $typeConcept, $authorised) {
            $institution = Institution::findOrFail($agent->institution_id);
            $id = (string) Str::uuid7();
            $new = new Agent(['institution_id' => $institution->id, 'iri' => $institution->base_iri.'/'.Agent::IRI_SEGMENT.'/'.$id, 'agent_type_id' => $typeConcept->id]);
            $new->id = $id;
            $new->save();
            $hasAuthorised = $names->contains(fn (Name $n) => $n->name_type_id === $authorised);
            foreach ($names as $i => $name) {
                $name->update(['subject_id' => $new->id] + (! $hasAuthorised && $i === 0 ? ['name_type_id' => $authorised, 'is_original' => true] : []));
            }
            foreach (Relation::whereKey($relationIds)->get() as $relation) {
                foreach (['source', 'target'] as $side) {
                    if ($relation->{$side.'_table'} === 'agents' && $relation->{$side.'_id'} === $agent->id) {
                        $relation->update([$side.'_id' => $new->id]);
                    }
                }
            }

            return $new;
        }));
    }

    /**
     * Agents of the same type whose authorised name equals a name of another, ignoring case,
     * spaces and punctuation; pairs a person dismissed are left out.
     *
     * @return Collection<int, array{0: Agent, 1: Agent}>
     */
    public function duplicates(string $institutionId): Collection
    {
        // ponytail: every agent's names in memory; past some tens of thousands of agents this wants a normalised-name column with an index.
        $agents = Agent::where('institution_id', $institutionId)->whereNull('deleted_at')->with('names.nameType', 'agentType.labels', 'descriptionValues.element')->get();
        $dismissed = DB::table('agent_duplicate_dismissals')->get()->map(fn ($d) => $d->agent_id.'|'.$d->other_id)->flip();
        $byName = [];
        foreach ($agents as $agent) {
            foreach ($agent->names as $name) {
                $byName[$agent->agent_type_id.'|'.self::normalise($name->value)][$agent->id] = $agent;
            }
        }
        $pairs = [];
        foreach ($agents as $agent) {
            foreach ($agent->namesOfType('authorised') as $name) {
                foreach ($byName[$agent->agent_type_id.'|'.self::normalise($name->value)] ?? [] as $other) {
                    [$a, $b] = strcmp($agent->id, $other->id) < 0 ? [$agent, $other] : [$other, $agent];
                    if ($a->id !== $b->id && ! isset($dismissed[$a->id.'|'.$b->id])) {
                        $pairs[$a->id.'|'.$b->id] = [$a, $b];
                    }
                }
            }
        }

        return collect(array_values($pairs));
    }

    public function dismiss(Agent $agent, Agent $other, User $actor): void
    {
        [$a, $b] = strcmp($agent->id, $other->id) < 0 ? [$agent->id, $other->id] : [$other->id, $agent->id];
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($a, $b, $actor, $agent) {
            DB::table('agent_duplicate_dismissals')->insertOrIgnore(['agent_id' => $a, 'other_id' => $b, 'user_id' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            AuditLog::record(Vocab::action('update'), $agent, null, ['not_a_duplicate_of' => $a === $agent->id ? $b : $a]);
        }));
    }

    /**
     * How complete the authority record is: the share of ISAAR(CPF) elements with a value in
     * some language (with the identifier, description identifier, status and level of detail),
     * and the essential elements it lacks.
     *
     * @return array{percent: int, missing: list<string>}
     */
    public function completeness(Agent $agent): array
    {
        $elements = Elements::of(Elements::ISAAR_CPF);
        $filled = $elements->filter(fn (Concept $e) => $agent->valuesOf($e)->isNotEmpty())->count()
            + ($agent->identifier ? 1 : 0) + ($agent->description_identifier ? 1 : 0) + ($agent->description_status_id ? 1 : 0) + ($agent->description_detail_id ? 1 : 0);
        $total = $elements->count() + 4;
        $dated = $agent->dates->contains(fn ($d) => in_array($d->property->notation, ['existence', 'birth', 'death'], true))
            || $agent->descriptionValues->contains(fn ($v) => $v->element->notation === 'agent-dates-of-existence');
        $missing = array_values(array_filter([
            $dated ? null : self::ESSENTIAL[2],
            $agent->description_identifier ? null : self::ESSENTIAL[3],
        ]));

        return ['percent' => (int) round(100 * $filled / max(1, $total)), 'missing' => $missing];
    }

    public static function normalise(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($name))));
    }

    /** A relation that would connect the kept agent to itself, once the duplicate's end becomes the kept agent. */
    private function wouldBeReflexive(Relation $relation, string $side, Agent $kept): bool
    {
        $other = $side === 'source' ? 'target' : 'source';

        return $side !== 'context' && $relation->{$other.'_table'} === 'agents' && $relation->{$other.'_id'} === $kept->id;
    }

    /** A type changes only while no relation or birth or death date depends on the old one. */
    private function typeMayChange(Agent $agent, Concept $type): void
    {
        $related = Relation::where(fn ($q) => $q->where(['source_table' => 'agents', 'source_id' => $agent->id])
            ->orWhere(fn ($q) => $q->where(['target_table' => 'agents', 'target_id' => $agent->id])))->count();
        if ($related > 0) {
            throw new InvalidArgumentException("the type of entity cannot change while the agent takes part in $related ".($related === 1 ? 'relation' : 'relations').', which depend on it; remove them first');
        }
        if ($type->notation !== 'person' && $agent->dates()->whereHas('property', fn ($q) => $q->whereIn('notation', ['birth', 'death']))->exists()) {
            throw new InvalidArgumentException('only a person has birth and death dates; change them to dates of existence first');
        }
    }

    private function concept(string $iri): Concept
    {
        return Concept::where('iri', $iri)->whereNull('deprecated_at')->first()
            ?? throw new InvalidArgumentException("$iri is not a current concept");
    }
}
