<?php

// OpenRiC Workspace - delete a unit of description, a repository or a place.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// One rule for every kind of entity. Deleting marks the entity deleted, so its
// persistent IRI stays and its names, dates and identifiers stay with it in the
// store. The relations it takes part in, and its use as evidence, are removed
// with it, each audited. What would be left without a home blocks the delete:
// the units inside a unit, the units a repository holds, the places inside a
// place. Those are moved or deleted first.

namespace App\Description;

use App\Audit\AuditLog;
use App\Models\Place;
use App\Models\RecordResource;
use App\Models\Relation;
use App\Models\RelationEvidence;
use App\Models\Repository;
use App\Models\User;
use App\Relations\RelationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class EntityDeletion
{
    public function __construct(private RelationService $relations) {}

    /** @return list<string> why the entity cannot be deleted yet, in words; empty if it can */
    public function blockers(Model $entity): array
    {
        $count = match (true) {
            $entity instanceof RecordResource => [RecordResource::where('parent_id', $entity->id)->whereNull('deleted_at')->count(), 'unit of description inside it', 'units of description inside it'],
            $entity instanceof Repository => [RecordResource::where('repository_id', $entity->id)->whereNull('deleted_at')->count(), 'unit of description it holds', 'units of description it holds'],
            $entity instanceof Place => [Place::where('parent_id', $entity->id)->whereNull('deleted_at')->count(), 'place inside it', 'places inside it'],
            default => [0, '', ''],
        };

        return $count[0] > 0 ? ["It has {$count[0]} ".($count[0] === 1 ? $count[1] : $count[2]).'; move or delete '.($count[0] === 1 ? 'it' : 'them').' first.'] : [];
    }

    /** @return array{relations: int, evidence: int} what is removed with the entity */
    public function consequences(Model $entity): array
    {
        return [
            'relations' => $this->relationsOf($entity)->count(),
            'evidence' => $entity instanceof RecordResource ? RelationEvidence::where('record_resource_id', $entity->id)->count() : 0,
        ];
    }

    public function delete(Model $entity, ?User $actor = null): void
    {
        if ($blockers = $this->blockers($entity)) {
            throw new InvalidArgumentException(implode(' ', $blockers));
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($entity, $actor) {
            if ($entity instanceof RecordResource) {
                RelationEvidence::where('record_resource_id', $entity->id)->get()->each(fn ($e) => $this->relations->removeEvidence($e, $actor));
            }
            $this->relationsOf($entity)->get()->each(fn (Relation $r) => $this->relations->remove($r, $actor));
            $entity->forceFill(['deleted_at' => now()])->save();
        }));
    }

    private function relationsOf(Model $entity)
    {
        $end = fn (string $side) => fn ($q) => $q->where([$side.'_table' => $entity->getTable(), $side.'_id' => $entity->getKey()]);

        return Relation::where(fn ($q) => $q->where($end('source'))->orWhere($end('target'))->orWhere($end('context')));
    }
}
