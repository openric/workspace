<?php

// OpenRiC Workspace - the kinds of entity the Workspace describes, and where each lives.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Code, not data: each kind is a model class. Agents join here as they are
// built, together with their table in a relations migration.

namespace App\Relations;

use App\Models\Concept;
use App\Models\Place;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class EntityKinds
{
    /**
     * kind => class (its model), segment (of its URL), permission (record kind, ADR 0005),
     * readable (reading needs the read permission; otherwise any account of the institution
     * reads it), route (of its page), label (the name element that names it, null for a concept),
     * with (what its label needs loaded), noun (for people).
     */
    private const KINDS = [
        'record' => ['class' => RecordResource::class, 'segment' => 'records', 'permission' => 'description', 'readable' => true,
            'route' => 'records.show', 'label' => 'title', 'with' => ['names.nameType', 'descriptionValues.element', 'identifiers.identifierType'], 'noun' => 'unit of description'],
        'repository' => ['class' => Repository::class, 'segment' => 'repositories', 'permission' => 'repository', 'readable' => true,
            'route' => 'repositories.show', 'label' => 'authorised-name', 'with' => ['names.nameType', 'descriptionValues.element', 'identifiers.identifierType'], 'noun' => 'repository'],
        'place' => ['class' => Place::class, 'segment' => 'places', 'permission' => 'vocabulary', 'readable' => false,
            'route' => 'places.show', 'label' => 'place-name', 'with' => ['names.nameType', 'descriptionValues.element', 'identifiers.identifierType', 'parent.names.nameType'], 'noun' => 'place'],
        'concept' => ['class' => Concept::class, 'segment' => 'concepts', 'permission' => 'vocabulary', 'readable' => false,
            'route' => 'concepts.show', 'label' => null, 'with' => ['labels', 'vocabulary'], 'noun' => 'concept'],
    ];

    /** @return array{class: class-string<Model>, segment: string, permission: string, readable: bool, route: string, label: ?string, with: list<string>, noun: string}|null */
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

    /** The kinds a name in config workspace.relation_types stands for: a kind, or a group such as agent. */
    public static function expand(string $kindOrGroup): array
    {
        return config('workspace.relation_kind_groups.'.$kindOrGroup) ?? (isset(self::KINDS[$kindOrGroup]) ? [$kindOrGroup] : []);
    }

    /** The entities of a kind that exist in the account's institution: not deleted, and for a concept, local and current. */
    public static function existing(string $kind, string $institutionId): Builder
    {
        $class = self::KINDS[$kind]['class'];
        $query = $class::where('institution_id', $institutionId);

        return $kind === 'concept' ? $query->whereNull('deprecated_at') : $query->whereNull('deleted_at');
    }

    /** May the account read the entity? */
    public static function readable(User $user, Model $entity): bool
    {
        $definition = self::KINDS[self::of($entity)];
        if ($entity->getAttribute('institution_id') !== $user->institution_id) {
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
        return $entity instanceof Concept ? 'concept in '.$entity->vocabulary->title : self::KINDS[self::of($entity)]['noun'];
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
