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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RecordService
{
    public function __construct(private DescriptionService $descriptions, private IdentifierService $identifiers, private DateService $dates) {}

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
                'level_id' => $this->descriptions->concept($levelIri)->id,
                'repository_id' => $repository->id,
                'parent_id' => $parent?->id,
                'position' => $this->nextPosition($institution->id, $parent?->id),
            ]);
            $record->id = $id;
            $record->save();
            $this->describe($record, ['title' => $title], $language);
            $this->identifiers->setLocal($record, $identifier);

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
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($record, $identifier, $levelIri) {
            $record->update(['level_id' => $this->descriptions->concept($levelIri)->id]);
            $this->identifiers->setLocal($record, $identifier);
        }));
    }

    /** The description identifier and the status and level of detail (concept IRIs, or null), which AtoM keeps beside ISAD(G) 3.7. */
    public function updateControl(RecordResource $record, ?string $descriptionIdentifier, ?string $statusIri, ?string $detailIri, ?User $actor = null): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $record->update([
            'description_identifier' => ($descriptionIdentifier = trim((string) $descriptionIdentifier)) === '' ? null : $descriptionIdentifier,
            'description_status_id' => $statusIri ? $this->descriptions->concept($statusIri)->id : null,
            'description_detail_id' => $detailIri ? $this->descriptions->concept($detailIri)->id : null,
        ])));
    }

    /**
     * Set the unit's creation date (3.1.3) to the span of the creation dates of the units
     * inside it, as Heratio's calculate-dates does: from the earliest day to the latest.
     * Returns the EDTF interval, or null when no unit inside has a creation date.
     */
    public function calculateDates(RecordResource $record, ?User $actor = null): ?string
    {
        $ids = RecordResource::whereKey($record->descendantIds())->whereNull('deleted_at')->pluck('id');
        $span = DB::table('dates as d')->join('concepts as p', 'p.id', '=', 'd.property_id')
            ->where('d.subject_table', 'record_resources')->whereIn('d.subject_id', $ids)
            ->where('p.iri', 'https://workspace.openric.org/vocab/date-property/creation')
            ->selectRaw('min(d.earliest) as earliest, max(d.latest) as latest')->first();
        if ($span === null || $span->earliest === null || $span->latest === null) {
            return null;
        }
        $bound = fn (string $day, bool $end) => substr($day, 5) === ($end ? '12-31' : '01-01') ? substr($day, 0, 4) : substr($day, 0, 10);
        $edtf = $bound((string) $span->earliest, false).'/'.$bound((string) $span->latest, true);
        if ($bound((string) $span->earliest, false) === $bound((string) $span->latest, true)) {
            $edtf = $bound((string) $span->earliest, false);
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($record, $edtf) {
            $current = $record->dates()->whereHas('property', fn ($q) => $q->where('notation', 'creation'))->first();
            $current
                ? $this->dates->update($current, ['property' => 'creation', 'normalized' => $edtf, 'expressed' => $current->expressed, 'language' => $current->expressed_language,
                    'qualifier' => $current->qualifier?->notation, 'type' => $current->dateType?->notation])
                : $this->dates->add($record, ['property' => 'creation', 'normalized' => $edtf]);
        }));

        return $edtf;
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

    /**
     * Every unit below $top the account may read, by parent id, in order, with what the
     * finding aid, inventory and exports read loaded.
     * ponytail: the whole hierarchy in memory; a fonds past some tens of thousands of units wants loading by level.
     *
     * @return Collection<string, Collection<int, RecordResource>>
     */
    public function hierarchy(RecordResource $top, User $user)
    {
        return RecordResource::whereKey($top->descendantIds())->whereNull('deleted_at')
            ->with(self::DESCRIBED)->orderBy('position')->orderBy('id')->get()
            ->filter(fn (RecordResource $r) => $user->may('read', 'description', $r))
            ->groupBy('parent_id');
    }

    /** What reading a unit's description needs loaded. */
    public const DESCRIBED = ['descriptionValues.element', 'descriptionValues.state', 'names.nameType', 'names.state', 'identifiers.identifierType.labels',
        'level.labels', 'dates.property.labels', 'dates.qualifier.labels', 'descriptionStatus.labels', 'descriptionDetail.labels'];

    /** @return list<array{0: RecordResource, 1: int}> the units below $parentId depth first, with their depth */
    public static function flatten($byParent, string $parentId, int $depth = 1): array
    {
        $rows = [];
        foreach ($byParent[$parentId] ?? [] as $unit) {
            $rows[] = [$unit, $depth];
            $rows = [...$rows, ...self::flatten($byParent, $unit->id, $depth + 1)];
        }

        return $rows;
    }

    private function nextPosition(string $institutionId, ?string $parentId): int
    {
        return (int) RecordResource::where('institution_id', $institutionId)->where('parent_id', $parentId)->max('position') + 1;
    }
}
