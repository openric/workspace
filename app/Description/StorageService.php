<?php

// OpenRiC Workspace - physical storage: containers, what they hold and where they are kept (openricx:Container, extension ontology 1.6.0).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The functions of Heratio's ahg-storage-manage (containers with type, barcode,
// size, capacity, environment, status and security level; a storage location
// tree; placing, moving one or many and taking out of storage with a note;
// the movement history; holdings, box lists and capacity) were read from it;
// the code is the Workspace's. Where Heratio keeps a current-location table
// and a movement log, the Workspace keeps the relations themselves: where a
// container is kept is a rico:hasOrHadLocation relation to a storage place,
// dated from the day it came to the day it left, so the relations are the
// history and a move is the end of one and the start of the next. What a
// container holds is an openricx:isOrWasStoredIn relation, dated the same way.
// A storage place is a rico:Place of a storage place type.

namespace App\Description;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\Container;
use App\Models\Identifier;
use App\Models\Institution;
use App\Models\Name;
use App\Models\Place;
use App\Models\Relation;
use App\Models\User;
use App\Relations\RelationService;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class StorageService
{
    /** The bundled vocabularies of a container's facts. */
    public const STATUSES = Vocab::BASE.'container-status/';

    public const SECURITY_LEVELS = Vocab::BASE.'storage-security-level/';

    public const CAPACITY_UNITS = Vocab::BASE.'capacity-unit/';

    public const CONDITIONS = Vocab::BASE.'container-condition/';

    public const PLACE_TYPES = Vocab::BASE.'storage-place-type/';

    /** The levels of the placement form, from the top: the bundled storage place types, as Heratio's box form has them. */
    public const LEVELS = ['building', 'floor', 'room', 'strongroom', 'aisle', 'bay', 'rack', 'shelf'];

    public function __construct(private DescriptionService $descriptions, private IdentifierService $identifiers, private RelationService $relations,
        private PlaceService $places) {}

    /**
     * The name a storage place is kept under. Below building level a bare number or code ("1", "C3", "12B") gets its
     * level in front ("Floor 1", "Room C3"), so the tree reads on its own; a name already in words is kept as typed.
     * Heratio names its places so; the rule is the same so that both find the same place.
     */
    public function placeName(string $level, string $name): string
    {
        $name = trim($name);
        if ($level !== 'building' && in_array($level, self::LEVELS, true) && preg_match('/^[A-Za-z]{0,3}\d+[A-Za-z]{0,3}$/', $name)) {
            return $this->concept(self::PLACE_TYPES.$level)->label(['en']).' '.$name;
        }

        return $name;
    }

    /**
     * The storage place the levels of the placement form name (level => name; empty levels are skipped), found level by
     * level under the one above by its type and its name without regard to case or surrounding space, and made where it
     * is missing if the account may make places. Null when every level is empty. Nothing is made unless every level is found or may be made.
     *
     * @param  array<string, ?string>  $levels
     */
    public function placeByLevels(Institution $institution, array $levels, string $language, User $actor): ?Place
    {
        $named = [];
        foreach (self::LEVELS as $level) {
            if (($name = trim((string) ($levels[$level] ?? ''))) !== '') {
                $named[$level] = $this->placeName($level, $name);
            }
        }
        if ($named === []) {
            return null;
        }

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($institution, $named, $language, $actor) {
            $parent = null;
            foreach ($named as $level => $name) {
                $type = $this->concept(self::PLACE_TYPES.$level);
                $found = Place::where(['institution_id' => $institution->id, 'place_type_id' => $type->id, 'parent_id' => $parent?->id])->whereNull('deleted_at')
                    ->whereIn('id', Name::where('subject_table', 'places')->whereRaw('lower(trim(value)) = ?', [mb_strtolower($name)])
                        ->whereHas('nameType', fn ($q) => $q->where('iri', Vocab::NAME_TYPES.'authorised'))->select('subject_id'))->orderBy('created_at')->first();
                if ($found === null) {
                    if (! $actor->may('create', 'vocabulary')) {
                        throw new InvalidArgumentException('there is no '.mb_strtolower((string) $type->label(['en']))." $name ".($parent ? 'in '.$this->path($parent) : 'at the top').', and you may not make storage places');
                    }
                    $found = $this->places->create($institution, $name, $language, $parent, $actor);
                    $this->places->updateFacts($found, $type->id, null, null, null, $actor);
                }
                $parent = $found;
            }

            return $parent;
        }));
    }

    /** @return array<string, string> level => name of the place and the places it lies in, for the placement form */
    public function levelsOf(?Place $place): array
    {
        $levels = [];
        foreach ($place ? [...$place->ancestors(), $place] : [] as $p) {
            $p->loadMissing('placeType');
            if ($p->placeType && str_starts_with($p->placeType->iri, self::PLACE_TYPES)) {
                $levels[$p->placeType->notation] = (string) $p->described('place-name', ['en']);
            }
        }

        return $levels;
    }

    public function create(Institution $institution, string $name, string $language, ?string $typeId = null, ?string $identifier = null, ?User $actor = null): Container
    {
        $type = $this->choice($this->types($institution->id), $typeId, 'a container type is a concept of the container-type vocabulary or of one of the institution\'s container-type vocabularies');

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($institution, $name, $language, $type, $identifier) {
            $id = (string) Str::uuid7();
            $container = new Container(['institution_id' => $institution->id, 'iri' => $institution->base_iri.'/'.Container::IRI_SEGMENT.'/'.$id,
                'container_type_id' => $type?->id, 'status_id' => $this->concept(self::STATUSES.'active')->id]);
            $container->id = $id;
            $container->save();
            $this->describe($container, ['container-name' => $name], $language);
            $this->identifiers->setLocal($container, $identifier);

            return $container;
        }));
    }

    /** @param  array<string, ?string>  $values  element notation => text, in one language */
    public function describe(Container $container, array $values, string $language, ?User $actor = null): void
    {
        $this->descriptions->describe($container, Elements::CONTAINER, $values, $language, 'container-name', $actor);
    }

    /**
     * The type (a concept id from types()), status, security level and capacity unit (notations),
     * the dimensions in centimetres, the capacity and what of it is used, and the environment.
     *
     * @param  array<string, mixed>  $fields
     */
    public function updateFacts(Container $container, array $fields, ?User $actor = null): void
    {
        $text = fn (string $key) => ($v = trim((string) ($fields[$key] ?? ''))) === '' ? null : $v;
        $type = $this->choice($this->types($container->institution_id), $text('type'), 'a container type is a concept of the container-type vocabulary or of one of the institution\'s container-type vocabularies');
        $number = function (string $key, float $min, ?float $max, string $what) use ($text) {
            $value = $text($key);
            if ($value !== null && (! is_numeric($value) || (float) $value < $min || ($max !== null && (float) $value > $max))) {
                throw new InvalidArgumentException($max !== null ? __('give the :what as a number from :min to :max', ['what' => $what, 'min' => $min, 'max' => $max]) : __($min > 0 ? 'give the :what as a number above 0' : 'give the :what as a number of 0 or more', ['what' => $what]));
            }

            return $value;
        };
        $values = [
            'container_type_id' => $type?->id,
            'status_id' => $text('status') ? $this->concept(self::STATUSES.$text('status'))->id : null,
            'security_level_id' => $text('security_level') ? $this->concept(self::SECURITY_LEVELS.$text('security_level'))->id : null,
            'capacity_unit_id' => $text('capacity_unit') ? $this->concept(self::CAPACITY_UNITS.$text('capacity_unit'))->id : null,
            // PSIS's accession container: its condition, weight and how many items it holds.
            'condition_id' => $text('condition') ? $this->concept(self::CONDITIONS.$text('condition'))->id : null,
            'weight_kg' => $number('weight_kg', 0.01, null, __('weight in kilograms')),
            'item_count' => $text('item_count') === null ? null : (ctype_digit($text('item_count')) ? (int) $text('item_count') : throw new InvalidArgumentException(__('give the number of items as a whole number'))),
            'width_cm' => $number('width_cm', 0.1, null, __('width in centimetres')), 'height_cm' => $number('height_cm', 0.1, null, __('height in centimetres')),
            'depth_cm' => $number('depth_cm', 0.1, null, __('depth in centimetres')),
            'capacity' => $number('capacity', 0, null, __('capacity')), 'capacity_used' => $number('capacity_used', 0, null, __('capacity used')),
            'climate_controlled' => array_key_exists('climate_controlled', $fields) && $fields['climate_controlled'] !== null && $fields['climate_controlled'] !== '' ? (bool) $fields['climate_controlled'] : null,
            'temperature_min' => $number('temperature_min', -100, 100, __('lowest temperature in degrees Celsius')), 'temperature_max' => $number('temperature_max', -100, 100, __('highest temperature in degrees Celsius')),
            'humidity_min' => $number('humidity_min', 0, 100, __('lowest relative humidity in per cent')), 'humidity_max' => $number('humidity_max', 0, 100, __('highest relative humidity in per cent')),
        ];
        if (($values['capacity'] !== null || $values['capacity_used'] !== null) && $values['capacity_unit_id'] === null) {
            throw new InvalidArgumentException(__('give the unit the capacity is counted in'));
        }
        foreach ([['temperature_min', 'temperature_max', __('temperature')], ['humidity_min', 'humidity_max', __('humidity')]] as [$low, $high, $what]) {
            if ($values[$low] !== null && $values[$high] !== null && (float) $values[$low] > (float) $values[$high]) {
                throw new InvalidArgumentException(__('the lowest :what is above the highest', ['what' => $what]));
            }
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $container->update($values)));
    }

    /** The barcode: one per container, and one container per barcode in the institution, so scanning it finds one box. */
    public function setBarcode(Container $container, ?string $barcode, ?User $actor = null): void
    {
        $barcode = trim((string) $barcode);
        $type = $this->concept(Vocab::IDENTIFIER_TYPES.'barcode');
        $current = Identifier::where(['subject_table' => 'containers', 'subject_id' => $container->id, 'identifier_type_id' => $type->id])->first();
        if ($barcode !== '' && $barcode !== $current?->value && $this->byBarcode($container->institution_id, $barcode)) {
            throw new InvalidArgumentException("another container has the barcode $barcode");
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($container, $barcode, $current) {
            match (true) {
                $barcode === '' => $current && $this->identifiers->remove($current),
                $current === null => $this->identifiers->add($container, ['type' => 'barcode', 'value' => $barcode]),
                $current->value !== $barcode => $this->identifiers->update($current, ['type' => 'barcode', 'value' => $barcode]),
                default => null,
            };
        }));
    }

    public function byBarcode(string $institutionId, string $barcode): ?Container
    {
        return Container::where('institution_id', $institutionId)->whereNull('deleted_at')
            ->whereIn('id', Identifier::where(['subject_table' => 'containers', 'value' => $barcode])
                ->whereHas('identifierType', fn ($q) => $q->where('iri', Vocab::IDENTIFIER_TYPES.'barcode'))->select('subject_id'))->first();
    }

    /**
     * Keep the container in a storage place from a day (today unless given), or (null) take it out of storage. Where it was
     * kept ends that day. A note says why, such as "returned from the reading room". Moving it where it is already is refused.
     */
    public function move(Container $container, ?Place $place, ?string $note = null, ?string $language = null, ?User $actor = null, ?string $day = null): void
    {
        $day ??= now()->toDateString();
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            throw new InvalidArgumentException(__('give the day of the move as YYYY-MM-DD'));
        }
        if ($place !== null && (! $this->isStoragePlace($place) || $place->institution_id !== $container->institution_id || $place->deleted_at !== null)) {
            throw new InvalidArgumentException(__('a container is kept in a storage place of the institution: a place of a storage place type'));
        }
        $current = $this->currentLocation($container);
        if ($current?->target_id === $place?->id) {
            throw new InvalidArgumentException($place ? 'the container is kept there already' : 'the container is not in storage');
        }
        $note = trim((string) $note) ?: null;
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($container, $place, $note, $language, $actor, $day, $current) {
            if ($current) {
                $this->relations->end($current, $day, $actor);
            }
            if ($place) {
                $this->relations->create($container, 'location', $place, array_filter(['from' => $day, 'description' => $note, 'language' => $note ? ($language ?? 'en') : null]), $actor);
            }
        }));
    }

    /**
     * Move several containers in one go, as Heratio's bulk move does; those kept there already are left as they are.
     *
     * @param  iterable<Container>  $containers
     * @return int how many moved
     */
    public function moveMany(iterable $containers, ?Place $place, ?string $note = null, ?string $language = null, ?User $actor = null): int
    {
        return DB::transaction(function () use ($containers, $place, $note, $language, $actor) {
            $moved = 0;
            foreach ($containers as $container) {
                if ($this->currentLocation($container)?->target_id !== $place?->id) {
                    $this->move($container, $place, $note, $language, $actor);
                    $moved++;
                }
            }

            return $moved;
        });
    }

    /** The relation that says where the container is kept now: a storage location with no end. */
    public function currentLocation(Container $container): ?Relation
    {
        return $this->locations($container)->first(fn (Relation $r) => $r->datedAs('end') === null);
    }

    /** @return Collection<int, Relation> where the container was and is kept, earliest first: its movements */
    public function locations(Container $container): Collection
    {
        return Relation::where(['source_table' => 'containers', 'source_id' => $container->id])
            ->whereHas('relationType', fn ($q) => $q->where('notation', 'location'))
            ->with('dates.property')->get()
            ->sortBy(fn (Relation $r) => ($r->datedAs('beginning')?->normalized_value ?? '').'|'.$r->created_at->format('Y-m-d H:i:s.u').'|'.$r->id)->values();
    }

    /** The place a container is kept in now, or null. */
    public function place(Container $container): ?Place
    {
        $relation = $this->currentLocation($container);

        return $relation ? Place::find($relation->target_id) : null;
    }

    /** The storage place and the places it lies in, from the top: Building A, Strongroom 2, Shelf 4. */
    public function path(Place $place): string
    {
        return collect([...$place->ancestors(), $place])->map(fn (Place $p) => $p->described('place-name', ['en']))->join(', ');
    }

    /** What the container holds now: units of description, instantiations and smaller containers, by relation. */
    public function contents(Container $container): Collection
    {
        return Relation::where(['target_table' => 'containers', 'target_id' => $container->id])
            ->whereHas('relationType', fn ($q) => $q->where('notation', 'storage'))->with('dates.property')->get()
            ->filter(fn (Relation $r) => $r->datedAs('end') === null)->values();
    }

    /** Is the place a storage place: typed from the storage-place-type vocabulary or one of the institution's? */
    public function isStoragePlace(Place $place): bool
    {
        return $place->place_type_id !== null && $this->placeTypes($place->institution_id)->contains('id', $place->place_type_id);
    }

    /** Limit a query of places to the storage places ($storage) or to the others. */
    public function scopeStorage(Builder $places, string $institutionId, bool $storage): Builder
    {
        $types = $this->placeTypes($institutionId)->pluck('id')->all();

        return $storage ? $places->whereIn('place_type_id', $types)
            : $places->where(fn ($q) => $q->whereNull('place_type_id')->orWhereNotIn('place_type_id', $types));
    }

    /** The storage places of the institution, in tree order with their paths. */
    public function storagePlaces(string $institutionId): Collection
    {
        $types = $this->placeTypes($institutionId)->pluck('id');

        return Place::where('institution_id', $institutionId)->whereNull('deleted_at')->whereIn('place_type_id', $types)
            ->with('names.nameType', 'placeType.labels', 'parent')->get()
            ->map(fn (Place $p) => [$p, $this->path($p)])->sortBy(fn ($pair) => mb_strtolower($pair[1]))->map(fn ($pair) => $pair[0]->setAttribute('path', $pair[1]))->values();
    }

    /** The containers kept in the place now, and in the places inside it with $below. */
    public function containersIn(Place $place, bool $below = false): Collection
    {
        $ids = [$place->id];
        if ($below) {
            for ($frontier = [$place->id]; $frontier !== [];) {
                $frontier = Place::whereIn('parent_id', $frontier)->whereNull('deleted_at')->pluck('id')->all();
                $ids = [...$ids, ...$frontier];
            }
        }
        $here = Relation::where(['source_table' => 'containers', 'target_table' => 'places'])->whereIn('target_id', $ids)
            ->whereHas('relationType', fn ($q) => $q->where('notation', 'location'))->with('dates.property')->get()
            ->filter(fn (Relation $r) => $r->datedAs('end') === null)->pluck('source_id');

        return Container::whereIn('id', $here)->whereNull('deleted_at')->with('names.nameType', 'containerType.labels', 'capacityUnit.labels', 'identifiers.identifierType')->get()
            ->sortBy(fn (Container $c) => mb_strtolower((string) $c->described('container-name', ['en'])))->values();
    }

    /**
     * The capacity of the containers in the place and below it, per unit, never added across units.
     *
     * @return array<string, array{capacity: float, used: float}> unit label => sums
     */
    public function capacity(Place $place): array
    {
        $sums = [];
        foreach ($this->containersIn($place, true) as $container) {
            if ($container->capacityUnit === null) {
                continue;
            }
            $unit = $container->capacityUnit->label(['en']);
            $sums[$unit] ??= ['capacity' => 0.0, 'used' => 0.0];
            $sums[$unit]['capacity'] += (float) $container->capacity;
            $sums[$unit]['used'] += (float) $container->capacity_used;
        }

        return $sums;
    }

    /** Containers kept nowhere now, for placing. */
    public function unplaced(string $institutionId): Collection
    {
        $placed = Relation::where('source_table', 'containers')->whereHas('relationType', fn ($q) => $q->where('notation', 'location'))
            ->whereDoesntHave('dates', fn ($q) => $q->whereHas('property', fn ($q) => $q->where('iri', Vocab::BASE.'date-property/end')))->select('source_id');

        return Container::where('institution_id', $institutionId)->whereNull('deleted_at')->whereNotIn('id', $placed)->with('names.nameType')->get()
            ->sortBy(fn (Container $c) => mb_strtolower((string) $c->described('container-name', ['en'])))->values();
    }

    /** The container types the institution may give: the bundled vocabulary, then its own, each by label. */
    public function types(string $institutionId): Collection
    {
        return $this->bundledThenLocal(Container::TYPES, 'container-type', $institutionId);
    }

    /** The storage place types: the bundled vocabulary, then the institution's own. */
    public function placeTypes(string $institutionId): Collection
    {
        return $this->bundledThenLocal(self::PLACE_TYPES, 'storage-place-type', $institutionId);
    }

    private function bundledThenLocal(string $vocabulary, string $kind, string $institutionId): Collection
    {
        return Concept::bundledThenLocal($vocabulary, $kind, $institutionId);
    }

    private function choice(Collection $choices, ?string $id, string $message): ?Concept
    {
        if ($id === null || $id === '') {
            return null;
        }

        return $choices->firstWhere('id', $id) ?? throw new InvalidArgumentException($message);
    }

    private function concept(string $iri): Concept
    {
        return $this->descriptions->concept($iri);
    }
}
