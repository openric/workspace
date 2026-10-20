<?php

// OpenRiC Workspace - create, describe and arrange places (rico:Place; ADR 0006).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Heratio keeps places as terms of a Places taxonomy; here a place is an
// entity with rico:PlaceName names, a place type, coordinates, the place
// that contains it, dates, identifiers and relations. Deleting is
// EntityDeletion's, as for every entity.

namespace App\Description;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\Place;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PlaceService
{
    public function __construct(private DescriptionService $descriptions) {}

    public function create(Institution $institution, string $name, string $language, ?Place $parent = null, ?User $actor = null): Place
    {
        if ($parent && ($parent->institution_id !== $institution->id || $parent->deleted_at !== null)) {
            throw new InvalidArgumentException('a place lies in a place of the institution');
        }

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($institution, $name, $language, $parent) {
            $id = (string) Str::uuid7();
            $place = new Place(['institution_id' => $institution->id, 'iri' => $institution->base_iri.'/'.Place::IRI_SEGMENT.'/'.$id, 'parent_id' => $parent?->id]);
            $place->id = $id;
            $place->save();
            $this->describe($place, ['place-name' => $name], $language);

            return $place;
        }));
    }

    /**
     * Set the values of the place's elements in one language; the authorised name must keep a value.
     *
     * @param  array<string, ?string>  $values  element notation => text
     */
    public function describe(Place $place, array $values, string $language, ?User $actor = null): void
    {
        $this->descriptions->describe($place, Elements::PLACE, $values, $language, 'place-name', $actor);
    }

    /** The place type (a concept id of a place-type or storage-place-type vocabulary of the institution or of the bundled storage-place-type vocabulary, or null) and coordinates (decimal degrees, metres). */
    public function updateFacts(Place $place, ?string $typeId, ?string $latitude, ?string $longitude, ?string $altitude, ?User $actor = null): void
    {
        $type = null;
        if ($typeId !== null && $typeId !== '') {
            $type = Concept::where(['id' => $typeId, 'institution_id' => $place->institution_id])->whereNull('deprecated_at')
                ->whereHas('vocabulary.kind', fn ($q) => $q->whereIn('iri', [Vocab::SCHEME_KINDS.'place-type', Vocab::SCHEME_KINDS.'storage-place-type']))->first()
                ?? Concept::choices(StorageService::PLACE_TYPES)->whereKey($typeId)->first()
                ?? throw new InvalidArgumentException('a place type is a current concept of one of the institution\'s place-type or storage-place-type vocabularies, or of the storage-place-type vocabulary');
        }
        [$latitude, $longitude, $altitude] = array_map(fn ($v) => ($v = trim((string) $v)) === '' ? null : $v, [$latitude, $longitude, $altitude]);
        foreach (['latitude' => [$latitude, 90], 'longitude' => [$longitude, 180]] as $what => [$value, $limit]) {
            if ($value !== null && (! is_numeric($value) || abs((float) $value) > $limit)) {
                throw new InvalidArgumentException("give the $what in decimal degrees, from -$limit to $limit");
            }
        }
        if (($latitude === null) !== ($longitude === null) || ($altitude !== null && $latitude === null)) {
            throw new InvalidArgumentException('give both latitude and longitude, and an altitude only with them');
        }
        if ($altitude !== null && (! is_numeric($altitude) || abs((float) $altitude) >= 1000000)) {
            throw new InvalidArgumentException('give the altitude in metres');
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $place->update([
            'place_type_id' => $type?->id, 'latitude' => $latitude, 'longitude' => $longitude, 'altitude' => $altitude,
        ])));
    }

    /** Put the place in another place, or (null) at the top; never in itself or a place it contains (the database refuses that too). */
    public function move(Place $place, ?Place $parent, ?User $actor = null): void
    {
        if ($parent) {
            if ($parent->institution_id !== $place->institution_id || $parent->deleted_at !== null) {
                throw new InvalidArgumentException('a place lies in a place of the institution');
            }
            foreach ([$parent, ...$parent->ancestors()] as $above) {
                if ($above->id === $place->id) {
                    throw new InvalidArgumentException('a place cannot lie in itself or in a place it contains');
                }
            }
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $place->update(['parent_id' => $parent?->id])));
    }
}
