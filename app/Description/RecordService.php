<?php

// OpenRiC Workspace - create, describe, arrange and date units of description (ISAD(G)).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Moving or reordering a unit never changes its id or IRI (WS-002); the
// database refuses a cycle. The title (3.1.2) must keep a value.

namespace App\Description;

use App\Audit\AuditLog;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RecordService
{
    public function __construct(private DescriptionService $descriptions) {}

    public function create(Institution $institution, string $levelIri, string $title, string $language, ?RecordResource $parent = null, ?Repository $repository = null, ?string $identifier = null, ?User $actor = null): RecordResource
    {
        $repository ??= $parent?->repository;
        if ($repository === null || $repository->institution_id !== $institution->id) {
            throw new InvalidArgumentException('a unit of description needs a repository of the same institution that holds it');
        }

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($institution, $levelIri, $title, $language, $parent, $repository, $identifier) {
            $id = (string) Str::uuid7();
            // id is not fillable: set directly, because the IRI contains it.
            $record = new RecordResource([
                'institution_id' => $institution->id,
                'iri' => $institution->base_iri.'/'.RecordResource::IRI_SEGMENT.'/'.$id,
                'identifier' => $identifier === '' ? null : $identifier,
                'level_id' => $this->descriptions->concept($levelIri)->id,
                'repository_id' => $repository->id,
                'parent_id' => $parent?->id,
                'position' => $this->nextPosition($institution->id, $parent?->id),
            ]);
            $record->id = $id;
            $record->save();
            $this->describe($record, ['title' => $title], $language);

            return $record;
        }));
    }

    /** @param  array<string, ?string>  $values  ISAD(G) element notation => text */
    public function describe(RecordResource $record, array $values, string $language, ?User $actor = null): void
    {
        $this->descriptions->describe($record, Elements::ISAD_G, $values, $language, 'title', $actor);
    }

    public function updateIdentity(RecordResource $record, ?string $identifier, string $levelIri, ?User $actor = null): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $record->update([
            'identifier' => $identifier === '' ? null : $identifier,
            'level_id' => $this->descriptions->concept($levelIri)->id,
        ])));
    }

    /** Move under another unit, or to the top level when $parent is null; it goes last among its new siblings. */
    public function move(RecordResource $record, ?RecordResource $parent, ?User $actor = null): void
    {
        if ($parent !== null && $parent->institution_id !== $record->institution_id) {
            throw new InvalidArgumentException('a unit can only move within its institution');
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $record->update([
            'parent_id' => $parent?->id,
            'position' => $this->nextPosition($record->institution_id, $parent?->id),
        ])));
    }

    /** Swap with the sibling before (-1) or after (+1). */
    public function reorder(RecordResource $record, int $direction, ?User $actor = null): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($record, $direction) {
            $siblings = RecordResource::where('institution_id', $record->institution_id)->where('parent_id', $record->parent_id)
                ->whereNull('deleted_at')->orderBy('position')->orderBy('id')->lockForUpdate()->get()->values();
            $at = $siblings->search(fn ($s) => $s->id === $record->id);
            $to = $at + ($direction < 0 ? -1 : 1);
            if ($at === false || ! isset($siblings[$to])) {
                return;
            }
            // Renumber so positions are distinct, then swap the two.
            foreach ($siblings as $i => $sibling) {
                $position = $i === $at ? $to : ($i === $to ? $at : $i);
                if ($sibling->position !== $position) {
                    $sibling->update(['position' => $position]);
                }
            }
        }));
    }

    private function nextPosition(string $institutionId, ?string $parentId): int
    {
        return (int) RecordResource::where('institution_id', $institutionId)->where('parent_id', $parentId)->max('position') + 1;
    }
}
