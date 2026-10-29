<?php

// OpenRiC Workspace - the kinds of entity the Workspace describes, and where each lives.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Code, not data: each kind is a model class, and joins here together with
// its table in a relations migration. Where relations take only some agents,
// A relation type's pairs (relation_type_pairs) name a kind with types after a colon, such
// as agent:person or agent:family|corporate-body (agent-type vocabulary).

namespace App\Relations;

use App\Models\Activity;
use App\Models\Agent;
use App\Models\Concept;
use App\Models\Container;
use App\Models\Instantiation;
use App\Models\Place;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\User;
use App\Rights\CommunityProtocols;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class EntityKinds
{
    /**
     * kind => class (its model), segment (of its URL), permission (record kind, ADR 0005),
     * readable (reading needs the read permission; otherwise any account of the institution
     * reads it), route (of its page), index (the route of its list), label (the name element that names it, null for a concept),
     * with (what its label needs loaded), noun (for people), ric_class (the RiC-O class it is, null when its level
     * or agent type decides), ric_name (the RiC-O class of its names).
     */
    private const KINDS = [
        'record' => ['class' => RecordResource::class, 'segment' => 'records', 'permission' => 'description', 'readable' => true,
            'route' => 'records.show', 'index' => 'records.index', 'label' => 'title', 'with' => ['names.nameType', 'descriptionValues.element', 'identifiers.identifierType'], 'noun' => 'unit of description', 'ric_class' => null, 'ric_name' => 'rico:Title'],
        'repository' => ['class' => Repository::class, 'segment' => 'repositories', 'permission' => 'repository', 'readable' => true,
            'route' => 'repositories.show', 'index' => 'repositories.index', 'label' => 'authorised-name', 'with' => ['names.nameType', 'descriptionValues.element', 'identifiers.identifierType'], 'noun' => 'repository', 'ric_class' => 'rico:CorporateBody', 'ric_name' => 'rico:AgentName'],
        'agent' => ['class' => Agent::class, 'segment' => 'agents', 'permission' => 'authority', 'readable' => true,
            'route' => 'agents.show', 'index' => 'agents.index', 'label' => 'agent-authorised-name', 'with' => ['names.nameType', 'descriptionValues.element', 'identifiers.identifierType', 'agentType.labels'], 'noun' => 'agent', 'ric_class' => null, 'ric_name' => 'rico:AgentName'],
        'place' => ['class' => Place::class, 'segment' => 'places', 'permission' => 'vocabulary', 'readable' => false,
            'route' => 'places.show', 'index' => 'places.index', 'label' => 'place-name', 'with' => ['names.nameType', 'descriptionValues.element', 'identifiers.identifierType', 'parent.names.nameType'], 'noun' => 'place', 'ric_class' => 'rico:Place', 'ric_name' => 'rico:PlaceName'],
        'activity' => ['class' => Activity::class, 'segment' => 'activities', 'permission' => 'authority', 'readable' => true,
            'route' => 'activities.show', 'index' => 'activities.index', 'label' => 'activity-name', 'with' => ['names.nameType', 'descriptionValues.element', 'identifiers.identifierType', 'activityType.labels'], 'noun' => 'activity', 'ric_class' => 'rico:Activity', 'ric_name' => 'rico:Name'],
        'instantiation' => ['class' => Instantiation::class, 'segment' => 'instantiations', 'permission' => 'description', 'readable' => true,
            'route' => 'instantiations.show', 'index' => null, 'label' => 'instantiation-title', 'with' => ['names.nameType', 'descriptionValues.element', 'identifiers.identifierType'], 'noun' => 'instantiation', 'ric_class' => 'rico:Instantiation', 'ric_name' => 'rico:Title'],
        'container' => ['class' => Container::class, 'segment' => 'containers', 'permission' => 'storage', 'readable' => true,
            'route' => 'containers.show', 'index' => 'containers.index', 'label' => 'container-name', 'with' => ['names.nameType', 'descriptionValues.element', 'identifiers.identifierType', 'containerType.labels'], 'noun' => 'container', 'ric_class' => 'openricx:Container', 'ric_name' => 'rico:Name'],
        'concept' => ['class' => Concept::class, 'segment' => 'concepts', 'permission' => 'vocabulary', 'readable' => false,
            'route' => 'concepts.show', 'index' => null, 'label' => null, 'with' => ['labels', 'vocabulary'], 'noun' => 'concept', 'ric_class' => null, 'ric_name' => null],
    ];

    /** @return array{class: class-string<Model>, segment: string, permission: string, readable: bool, route: string, index: ?string, label: ?string, with: list<string>, noun: string}|null */
    public static function get(string $kind): ?array
    {
        return self::KINDS[$kind] ?? null;
    }

    public static function bySegment(string $segment): ?string
    {
        foreach (self::KINDS as $kind => $definition) {
            if ($definition['segment'] === $segment) {
                return $kind;
            }
        }

        return null;
    }

    /** @return string the URL segments of the kinds, for a route pattern such as records|repositories */
    public static function segments(): string
    {
        return implode('|', array_column(self::KINDS, 'segment'));
    }

    public static function of(Model $entity): string
    {
        foreach (self::KINDS as $kind => $definition) {
            if ($entity instanceof $definition['class']) {
                return $kind;
            }
        }
        throw new \InvalidArgumentException($entity::class.' is not a kind of entity the Workspace describes');
    }

    public static function byTable(string $table): ?string
    {
        foreach (self::KINDS as $kind => $definition) {
            if ((new $definition['class'])->getTable() === $table) {
                return $kind;
            }
        }

        return null;
    }

    /** The kinds a name in a relation type's pairs (relation_type_pairs) stands for: a kind, a kind with types (agent:person), or a group such as agent. */
    public static function expand(string $kindOrGroup): array
    {
        return config('workspace.relation_kind_groups.'.$kindOrGroup) ?? (isset(self::KINDS[strtok($kindOrGroup, ':')]) ? [$kindOrGroup] : []);
    }

    /** Is the entity of this kind, such as agent or agent:person|family? */
    public static function matches(Model $entity, string $spec): bool
    {
        [$kind, $types] = array_pad(explode(':', $spec, 2), 2, null);
        if (self::of($entity) !== $kind) {
            return false;
        }

        return $types === null || ($entity instanceof Agent && in_array($entity->typeNotation(), explode('|', $types), true));
    }

    /** @param  list<string>  $specs */
    public static function matchesAny(Model $entity, array $specs): bool
    {
        foreach ($specs as $spec) {
            if (self::matches($entity, $spec)) {
                return true;
            }
        }

        return false;
    }

    /** The entities of a kind (or kind:types) that exist in the account's institution: not deleted, and for a concept, local and current. */
    public static function existing(string $spec, string $institutionId): Builder
    {
        [$kind, $types] = array_pad(explode(':', $spec, 2), 2, null);
        $class = self::KINDS[$kind]['class'];
        $query = $class::where('institution_id', $institutionId);
        if ($types !== null) {
            $query->whereHas('agentType', fn ($q) => $q->whereIn('iri', array_map(fn ($t) => Agent::TYPES.$t, explode('|', $types))));
        }

        return $kind === 'concept' ? $query->whereNull('deprecated_at') : $query->whereNull('deleted_at');
    }

    /** May the account read the entity? */
    public static function readable(User $user, Model $entity): bool
    {
        $definition = self::KINDS[self::of($entity)];
        if ($entity->getAttribute('institution_id') !== $user->institution_id) {
            return false;
        }

        if ($entity instanceof Agent && $entity->isEmbargoed() && ! $user->may('update', 'authority', $entity)) {
            return false;
        }
        // A community protocol withholds the term or record from who may not change it (Heratio's TermProtocolGate).
        if (! app(CommunityProtocols::class)->allows($user, $entity, $definition['permission'])) {
            return false;
        }

        return ! $definition['readable'] || $user->may('read', $definition['permission'], $entity);
    }

    /** The entity's name for people: its authorised name or title, or a concept's label. */
    public static function label(Model $entity, array $languages = ['en']): string
    {
        if ($entity instanceof Concept) {
            return $entity->anyLabel([...$languages, 'en', 'und']);
        }

        return (string) $entity->described(self::KINDS[self::of($entity)]['label'], $languages);
    }

    /** What kind of thing it is, for people: a concept says its vocabulary. */
    public static function noun(Model $entity): string
    {
        return match (true) {
            $entity instanceof Concept => 'concept in '.$entity->vocabulary->title,
            $entity instanceof Agent => mb_strtolower((string) $entity->agentType?->label(['en', 'und'])),
            default => self::KINDS[self::of($entity)]['noun'],
        };
    }

    /** The RiC-O class the entity is: a unit's level and an agent's type say which. */
    public static function ricClass(Model $entity): ?string
    {
        return match (true) {
            // An institution's own level may name no class: every unit is at least a rico:RecordResource.
            $entity instanceof RecordResource => $entity->level?->ricTerm() ?? 'rico:RecordResource',
            $entity instanceof Agent => $entity->agentType?->ricTerm(),
            default => self::KINDS[self::of($entity)]['ric_class'],
        };
    }

    /** The RiC-O class of the entity's names: rico:Title, rico:AgentName or rico:PlaceName. */
    public static function ricName(Model $entity): ?string
    {
        return self::KINDS[self::of($entity)]['ric_name'];
    }

    public static function permission(Model $entity): string
    {
        return self::KINDS[self::of($entity)]['permission'];
    }

    public static function route(Model $entity): string
    {
        return route(self::KINDS[self::of($entity)]['route'], $entity);
    }
}
