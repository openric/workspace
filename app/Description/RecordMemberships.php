<?php

// OpenRiC Workspace - a unit of description in record sets besides its parent, each a rico:Proxy with its own place in
// that set's order (roadmap phase 24, ADR 0019).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The parent (record_resources.parent_id) stays the arrangement the unit was made in: its reference code, holder, and
// what it inherits (embargoes, rights, access) come from there. A membership adds a context, not an inheritance.

namespace App\Description;

use App\Audit\AuditLog;
use App\Models\Institution;
use App\Models\RecordMembership;
use App\Models\RecordResource;
use App\Models\User;
use App\Relations\EntityKinds;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RecordMemberships
{
    /** Put the unit in the record set as well, last in that set's order unless a position is given. */
    public function add(RecordResource $unit, RecordResource $set, ?User $actor = null, ?int $position = null, ?string $note = null): RecordMembership
    {
        if ($unit->institution_id !== $set->institution_id || $set->deleted_at !== null || $unit->deleted_at !== null) {
            throw new InvalidArgumentException(__('Choose a record set of this institution.'));
        }
        if (EntityKinds::ricClass($set) !== 'rico:RecordSet') {
            throw new InvalidArgumentException(__('A unit can only be in a record set, such as a fonds, series or file.'));
        }
        if ($set->id === $unit->id || $set->id === $unit->parent_id) {
            throw new InvalidArgumentException(__('That record set is already where the unit is arranged.'));
        }
        if (in_array($set->id, $unit->descendantIds(), true)) {
            throw new InvalidArgumentException(__('A unit cannot be in a record set inside itself.'));
        }
        if ($actor && ! $actor->may('update', 'description', $set)) {
            throw new InvalidArgumentException(__('You may not change what that record set holds.'));
        }
        if ($unit->memberships()->where('record_set_id', $set->id)->exists()) {
            throw new InvalidArgumentException(__('The unit is in that record set already.'));
        }
        $position ??= (int) $set->memberProxies()->max('position') + 1;

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($unit, $set, $actor, $position, $note) {
            $id = (string) Str::uuid7();
            $membership = new RecordMembership(['institution_id' => $unit->institution_id, 'iri' => Institution::findOrFail($unit->institution_id)->base_iri.'/'.RecordMembership::IRI_SEGMENT.'/'.$id,
                'record_resource_id' => $unit->id, 'record_set_id' => $set->id, 'position' => $position, 'note' => trim((string) $note) ?: null, 'created_by_id' => $actor?->id]);
            $membership->id = $id;
            $membership->save();

            return $membership;
        }));
    }

    /** End the membership; its proxy's IRI stays, marked ended. */
    public function remove(RecordMembership $membership, ?User $actor = null): void
    {
        $this->mustChange($membership, $actor);
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $membership->update(['deleted_at' => now()])));
    }

    /** One place earlier (up) or later in the record set's order. */
    public function move(RecordMembership $membership, string $direction, ?User $actor = null): void
    {
        $this->mustChange($membership, $actor);
        $siblings = $membership->recordSet->memberProxies()->get()->values();
        $at = $siblings->search(fn ($m) => $m->id === $membership->id);
        $to = $direction === 'up' ? $at - 1 : $at + 1;
        if ($at === false || ! isset($siblings[$to])) {
            return;
        }
        $order = $siblings->all();
        [$order[$at], $order[$to]] = [$order[$to], $order[$at]];
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($order) {
            foreach (array_values($order) as $i => $m) {
                $m->position === $i + 1 || $m->update(['position' => $i + 1]);
            }
        }));
    }

    private function mustChange(RecordMembership $membership, ?User $actor): void
    {
        if ($membership->deleted_at !== null) {
            throw new InvalidArgumentException(__('That membership has ended.'));
        }
        if ($actor && ! $actor->may('update', 'description', $membership->recordSet)) {
            throw new InvalidArgumentException(__('You may not change what that record set holds.'));
        }
    }
}
