<?php

// OpenRiC Workspace - add, change and remove an entity's dates (rico:Date; ADR 0006).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Description;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\RicDate;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class DateService
{
    private const PROPERTIES = 'https://workspace.openric.org/vocab/date-property/';

    private const QUALIFIERS = 'https://workspace.openric.org/vocab/date-qualifier/';

    private const TYPES = 'https://openric.org/vocab/date-type/';

    /**
     * Property and qualifier are notations of the Workspace vocabularies; type is a
     * notation of the OpenRiC date-type vocabulary.
     *
     * @param  array{property: string, normalized?: ?string, expressed?: ?string, language?: ?string, type?: ?string, qualifier?: ?string}  $fields
     */
    public function add(Model $subject, array $fields, ?User $actor = null): RicDate
    {
        $values = $this->values($fields);

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($subject, $values) {
            $institution = Institution::findOrFail($subject->getAttribute('institution_id'));
            $id = (string) Str::uuid7();
            $date = new RicDate([...$values, 'institution_id' => $institution->id, 'subject_table' => $subject->getTable(), 'subject_id' => $subject->getKey(),
                'iri' => $institution->base_iri.'/'.RicDate::IRI_SEGMENT.'/'.$id]);
            $date->id = $id;
            $date->save();

            return $date;
        }));
    }

    /** @param  array{property: string, normalized?: ?string, expressed?: ?string, language?: ?string, type?: ?string, qualifier?: ?string}  $fields */
    public function update(RicDate $date, array $fields, ?User $actor = null): void
    {
        $values = $this->values($fields);
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $date->update($values)));
    }

    /**
     * What the date form offers for an entity of this record kind: the date properties
     * config allows it, the qualifiers, and the OpenRiC date types.
     *
     * @return array{properties: Collection, qualifiers: Collection, types: Collection}
     */
    public function choices(string $kind): array
    {
        $allowed = config('workspace.date_properties.'.$kind, []);
        $concepts = fn (string $vocabulary) => Concept::choices($vocabulary)->get();

        return [
            'properties' => $concepts(self::PROPERTIES)->filter(fn (Concept $c) => in_array($c->notation, $allowed, true))
                ->sortBy(fn (Concept $c) => array_search($c->notation, $allowed, true))->values(),
            'qualifiers' => $concepts(self::QUALIFIERS),
            'types' => $concepts(self::TYPES),
        ];
    }

    public function remove(RicDate $date, ?User $actor = null): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $date->delete()));
    }

    /** Validate and turn the fields into columns. */
    private function values(array $fields): array
    {
        $normalized = trim((string) ($fields['normalized'] ?? ''));
        $expressed = trim((string) ($fields['expressed'] ?? ''));
        $language = trim((string) ($fields['language'] ?? ''));
        if ($normalized === '' && $expressed === '') {
            throw new InvalidArgumentException(__('a date needs a normalised value (EDTF) or a written form'));
        }
        $bounds = [null, null];
        if ($normalized !== '') {
            $bounds = Edtf::bounds($normalized) ?? throw new InvalidArgumentException(__(':date is not an EDTF date, such as 1902, 1902-03-15, 1902~ or 1902/1950', ['date' => $normalized]));
        }
        if ($expressed !== '' && ! Bcp47::valid($language)) {
            throw new InvalidArgumentException(__('give the language of the written form, as a BCP 47 tag such as en or af'));
        }

        return [
            'property_id' => $this->concept(self::PROPERTIES.($fields['property'] ?? ''))->id,
            'date_type_id' => ($fields['type'] ?? '') !== '' ? $this->concept(self::TYPES.$fields['type'])->id : null,
            'qualifier_id' => ($fields['qualifier'] ?? '') !== '' ? $this->concept(self::QUALIFIERS.$fields['qualifier'])->id : null,
            'normalized_value' => $normalized === '' ? null : $normalized,
            'expressed' => $expressed === '' ? null : $expressed,
            'expressed_language' => $expressed === '' ? null : $language,
            'earliest' => $bounds[0], 'latest' => $bounds[1],
        ];
    }

    private function concept(string $iri): Concept
    {
        return Concept::where('iri', $iri)->whereNull('deprecated_at')->first()
            ?? throw new InvalidArgumentException("$iri is not a current concept");
    }
}
