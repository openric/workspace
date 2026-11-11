<?php

// OpenRiC Workspace - create, describe, arrange and date units of description (ISAD(G)).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Moving or reordering a unit never changes its id or IRI (WS-002); the
// database refuses a cycle. The title (3.1.2) must keep a value.

namespace App\Description;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\RecordResourceType;
use App\Models\RecordWatermark;
use App\Models\Repository;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RecordService
{
    /** The display standards' own lists of types, by the standard that shows each: RAD, Dublin Core and MODS. */
    public const TYPE_LISTS = ['rad' => 'rad-material-designation', 'dc' => 'dcmi-type', 'mods' => 'mods-resource-type'];

    public function __construct(private DescriptionService $descriptions, private IdentifierService $identifiers, private DateService $dates) {}

    public function create(Institution $institution, string $levelIri, string $title, string $language, ?RecordResource $parent = null, ?Repository $repository = null, ?string $identifier = null, ?User $actor = null): RecordResource
    {
        $repository ??= $parent?->repository;
        if ($repository === null || $repository->institution_id !== $institution->id) {
            throw new InvalidArgumentException(__('a unit of description needs a repository of the same institution that holds it'));
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
        // Whichever standard shows it, a unit keeps ISAD(G)'s elements and those only DACS, RAD or MODS have.
        $this->descriptions->describe($record, [Elements::ISAD_G, Elements::OTHER_STANDARDS], $values, $language, 'title', $actor);
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
     * The description's own watermark (Heratio's object_watermark_setting): null to follow the institution's, 'none' for
     * none, or a watermark-type notation, with a position notation and an opacity that fall back to the institution's.
     */
    public function setWatermark(RecordResource $record, ?string $type, ?string $position = null, ?int $opacity = null, ?User $actor = null): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($record, $type, $position, $opacity) {
            $own = RecordWatermark::where('record_resource_id', $record->id)->first();
            if ($type === null) {
                $own?->delete();

                return;
            }
            $fields = ['enabled' => $type !== 'none',
                'watermark_type_id' => $type !== 'none' ? $this->descriptions->concept(Vocab::BASE.'watermark-type/'.$type)->id : null,
                'watermark_position_id' => $position ? $this->descriptions->concept(Vocab::BASE.'watermark-position/'.$position)->id : null,
                'opacity' => $opacity];
            $own ? $own->update($fields) : RecordWatermark::create($fields + ['institution_id' => $record->institution_id, 'record_resource_id' => $record->id]);
        }));
    }

    /** AtoM's "View children as compound?": whether the public page shows the pictures of the units inside as one strip. */
    public function setDisplayAsCompound(RecordResource $record, bool $compound, ?User $actor = null): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $record->update(['display_as_compound' => $compound])));
    }

    /**
     * The standard the unit is shown and edited in (display-standard notation; empty shows it in ISAD(G)), and with
     * $inside that of every unit below it, as Heratio's "Make this selection the new default for existing children".
     */
    public function setDisplayStandard(RecordResource $record, ?string $standard, bool $inside = false, ?User $actor = null): void
    {
        $id = ($standard = trim((string) $standard)) === '' ? null
            : ($this->descriptions->concept(Vocab::BASE.'display-standard/'.$standard)->id);
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($record, $id, $inside) {
            $record->update(['display_standard_id' => $id]);
            if ($inside) {
                RecordResource::whereIn('id', $record->descendantIds())->get()->each(fn (RecordResource $unit) => $unit->update(['display_standard_id' => $id]));
            }
        }));
        $record->unsetRelation('displayStandard');
    }

    /**
     * The types one of a display standard's lists gives the unit (rad-material-designation, dcmi-type or
     * mods-resource-type), replacing those it had from that list.
     *
     * @param  list<string>  $notations
     */
    public function setResourceTypes(RecordResource $record, string $list, array $notations, ?User $actor = null): void
    {
        $list = in_array($list, self::TYPE_LISTS, true) ? Vocab::BASE.$list.'/' : throw new InvalidArgumentException("$list is not a list of types");
        $chosen = Concept::choices($list)->whereIn('notation', array_values(array_unique($notations)))->pluck('id')->all();
        count($chosen) === count(array_unique($notations)) || throw new InvalidArgumentException(__('choose types from the list'));
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($record, $list, $chosen) {
            $record->resourceTypes()->whereHas('type.vocabulary', fn ($q) => $q->where('iri', $list))->whereNotIn('type_id', $chosen)->get()->each->delete();
            foreach ($chosen as $type) {
                RecordResourceType::firstOrCreate(['record_resource_id' => $record->id, 'type_id' => $type], ['institution_id' => $record->institution_id]);
            }
        }));
        $record->unsetRelation('resourceTypes');
    }

    /** The standard the description was written to (AtoM's source_standard), such as ISAD(G) or DACS. */
    public function setSourceStandard(RecordResource $record, ?string $standard, ?User $actor = null): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $record->update(['source_standard' => ($standard = trim((string) $standard)) === '' ? null : $standard])));
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
            throw new InvalidArgumentException(__('a unit can only move within its institution'));
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

    /** Units a staff page lists inside another at once, as the public record page does since v0.0.146 (#8). */
    public const INSIDE_PAGE = 100;

    /**
     * The first $count units of an ordered query that $readable allows, after skipping $skip of them, and whether there are
     * more: read a chunk at a time and stopped as soon as they are found, so a branch of 10,000 costs a page's checks (#8).
     * ponytail: where the account may read few of a branch's many units, it still reads the branch through; the upgrade is
     * the account's read rights in SQL, as PublicCatalogue::ids() decides the public's.
     *
     * @param  Builder<RecordResource>  $query  ordered
     * @param  callable(RecordResource): bool  $readable
     * @return array{0: Collection<int, RecordResource>, 1: bool}
     */
    public static function firstReadable(Builder $query, callable $readable, int $count, int $skip = 0): array
    {
        $found = collect();
        foreach ($query->lazy(max(20, min($count + 1, 200))) as $unit) {
            if (! $readable($unit)) {
                continue;
            }
            if ($skip > 0) {
                $skip--;

                continue;
            }
            if ($found->count() === $count) {
                return [$found, true];
            }
            $found->push($unit);
        }

        return [$found, false];
    }

    /** Lines of the hierarchy page at once (#8): a fonds of 10,000 took four seconds on one page. */
    public const OUTLINE_PAGE = 500;

    /**
     * A page of the hierarchy below $top, depth first in the arrangement's order, for its outline: the units the account
     * may read, a unit it may not read hiding everything inside it as the tree does, each with its depth, its title in the
     * first of the languages that has one (as described() picks it) and its local reference code. The order comes from one
     * query; the rights are read only until the page is full (#8).
     *
     * @param  list<string>  $languages
     * @return array{units: list<array{unit: RecordResource, depth: int}>, titles: array<string, string>, codes: array<string, string>, more: bool}
     */
    public function outline(RecordResource $top, User $user, array $languages, int $page = 1): array
    {
        $rows = DB::select('WITH RECURSIVE t(id, parent_id, depth, path) AS (
                SELECT id, parent_id, 1, ARRAY[lpad(position::text, 10, \'0\') || id::text] FROM record_resources WHERE parent_id = ? AND deleted_at IS NULL
                UNION ALL SELECT r.id, r.parent_id, t.depth + 1, t.path || (lpad(r.position::text, 10, \'0\') || r.id::text)
                FROM record_resources r JOIN t ON r.parent_id = t.id WHERE r.deleted_at IS NULL)
            SELECT id, parent_id, depth FROM t ORDER BY path', [$top->id]);
        $skip = ($page - 1) * self::OUTLINE_PAGE;
        [$found, $hidden, $more, $models] = [[], [], false, collect()];
        foreach ($rows as $at => $row) {
            if (isset($hidden[$row->parent_id])) {
                $hidden[$row->id] = true; // inside a unit the account may not read

                continue;
            }
            if (! $models->has($row->id)) {
                // The next rows' units, loaded a page's worth at a time, for the rights to be read on them.
                $models = RecordResource::whereKey(array_column(array_slice($rows, $at, self::OUTLINE_PAGE), 'id'))->with('level.labels')->get()->keyBy('id');
            }
            $unit = $models->get($row->id);
            if (! $user->may('read', 'description', $unit)) {
                $hidden[$row->id] = true;

                continue;
            }
            if ($skip > 0) {
                $skip--;

                continue;
            }
            if (count($found) === self::OUTLINE_PAGE) {
                $more = true;
                break;
            }
            $found[] = ['unit' => $unit, 'depth' => (int) $row->depth];
        }
        $ids = array_map(fn ($f) => $f['unit']->id, $found);
        $titles = $ids === [] ? [] : collect(DB::select('SELECT e.id, n.value FROM unnest(?::uuid[]) e(id)
            JOIN LATERAL (SELECT value FROM names WHERE subject_table = ? AND subject_id = e.id AND name_type_id = (SELECT id FROM concepts WHERE iri = ?)
                ORDER BY array_position(?::text[], language::text) NULLS LAST, created_at LIMIT 1) n ON true',
            ['{'.implode(',', $ids).'}', 'record_resources', Vocab::NAME_TYPES.config('workspace.name_elements.title'), '{'.implode(',', array_map(fn ($l) => '"'.$l.'"', $languages)).'}']))
            ->pluck('value', 'id')->all();
        $codes = DB::table('identifiers')->where('subject_table', 'record_resources')->whereIn('subject_id', $ids)
            ->where('identifier_type_id', Concept::where('iri', Vocab::IDENTIFIER_TYPES.'local')->value('id'))->orderBy('created_at')->get(['subject_id', 'value'])
            ->unique('subject_id')->pluck('value', 'subject_id')->all();

        return ['units' => $found, 'titles' => $titles, 'codes' => $codes, 'more' => $more];
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
