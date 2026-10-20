<?php

// OpenRiC Workspace - the kinds of entity the Workspace describes, and where each lives.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Code, not data: each kind is a model class. Agents, places and concepts join
// here as they are built, together with their table in the relations migration.

namespace App\Relations;

use App\Models\RecordResource;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Model;

final class EntityKinds
{
    /** kind => [model class, URL segment, record kind for permissions (ADR 0005), route of its page, name element of its description] */
    private const KINDS = [
        'record' => [RecordResource::class, 'records', 'description', 'records.show', 'title'],
        'repository' => [Repository::class, 'repositories', 'repository', 'repositories.show', 'authorised-name'],
    ];

    /** @return array{0: class-string<Model>, 1: string, 2: string, 3: string, 4: string}|null */
    public static function get(string $kind): ?array
    {
        return self::KINDS[$kind] ?? null;
    }

    public static function bySegment(string $segment): ?string
    {
        foreach (self::KINDS as $kind => $definition) {
            if ($definition[1] === $segment) {
                return $kind;
            }
        }

        return null;
    }

    public static function of(Model $entity): string
    {
        foreach (self::KINDS as $kind => $definition) {
            if ($entity instanceof $definition[0]) {
                return $kind;
            }
        }
        throw new \InvalidArgumentException($entity::class.' is not a kind of entity the Workspace describes');
    }

    public static function byTable(string $table): ?string
    {
        foreach (self::KINDS as $kind => [$class]) {
            if ((new $class)->getTable() === $table) {
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

    /** The entity's name for people: its authorised name or title. */
    public static function label(Model $entity, array $languages = ['en']): string
    {
        return (string) $entity->described(self::KINDS[self::of($entity)][4], $languages);
    }

    public static function permission(Model $entity): string
    {
        return self::KINDS[self::of($entity)][2];
    }

    public static function route(Model $entity): string
    {
        return route(self::KINDS[self::of($entity)][3], $entity);
    }
}
