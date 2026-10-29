<?php

// OpenRiC Workspace - the elements of one ICA standard, from the description-element vocabulary.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Each element is a concept whose broader concept is its standard (isdiah,
// isad-g, isaar-cpf) or its kind of entity (place, activity); the grouping is data, never a list
// in code.

namespace App\Description;

use App\Models\Concept;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Collection;

final class Elements
{
    public const ISDIAH = 'isdiah';

    public const ISAD_G = 'isad-g';

    public const ISAAR_CPF = 'isaar-cpf';

    /** A place: no ICA standard covers it, so its elements have no numbers and one heading. */
    public const PLACE = 'place';

    /** An activity: numbered after ISDF where ISDF has the element. */
    public const ACTIVITY = 'activity';

    /** An instantiation: no ICA description standard covers it. */
    public const INSTANTIATION = 'instantiation';

    /** A storage container (openricx:Container): no ICA description standard covers it. */
    public const CONTAINER = 'container';

    /** An accession: numbered after CAAIS 1.0. */
    public const ACCESSION = 'accession';

    /** A donor agreement (a rico:Record): Heratio's fields, which no standard numbers. */
    public const AGREEMENT = 'agreement';

    /** The elements of an archival description that DACS, RAD or MODS have and ISAD(G) does not. */
    public const OTHER_STANDARDS = 'other-standards';

    /** The display standards other than ISAD(G) (display-standard vocabulary), whose elements are the display-element vocabulary. */
    public const DISPLAY = ['dacs', 'rad', 'dc', 'mods'];

    /** @var array<string, Collection<int, Concept>> */
    private static array $cache = [];

    /** Current elements of the standard, in the order of their numbers in it (an element added later still takes its place). */
    public static function of(string $standard): Collection
    {
        if (in_array($standard, self::DISPLAY, true)) {
            return self::$cache[$standard] ??= self::shown($standard);
        }

        return self::$cache[$standard] ??= Concept::current()
            ->whereHas('broader', fn ($q) => $q->where('iri', Vocab::element($standard)))
            ->with('labels')->orderBy('id')->get()
            ->sort(fn (Concept $a, Concept $b) => version_compare(self::number($a) ?? '9', self::number($b) ?? '9') ?: strcmp($a->id, $b->id))->values();
    }

    /**
     * The elements a display standard shows, in its order: each is the description element itself, so its values and
     * the form's field are the same in every standard, labelled and numbered as the standard has it, with the
     * element's definition as its help.
     */
    private static function shown(string $standard): Collection
    {
        $entries = Concept::current()->whereHas('broader', fn ($q) => $q->where('iri', Vocab::BASE.'display-element/'.$standard))->with('labels')->orderBy('id')->get();
        $shows = fn (Concept $entry) => preg_match('/shows ([a-z0-9-]+)/', (string) $entry->labels->firstWhere('language', 'en')?->scope_note, $m) ? $m[1] : null;
        $elements = Concept::current()->whereIn('iri', $entries->map(fn (Concept $e) => Vocab::element((string) $shows($e))))->with('labels')->get()->keyBy('notation');

        return new Collection($entries->map(function (Concept $entry) use ($shows, $elements, $standard) {
            $element = $elements[$shows($entry)] ?? null;
            if ($element === null) {
                return null;
            }
            $shown = clone $element;
            $shown->setRelation('labels', $entry->labels->map(function ($label) use ($element) {
                $own = $element->labels->firstWhere('language', $label->language) ?? $element->labels->firstWhere('language', 'en');

                return tap(clone $label, fn ($l) => [$l->definition, $l->scope_note] = [$own?->definition, $own?->scope_note]);
            }));
            $shown->setAttribute('shown_number', preg_match('/^'.strtoupper($standard).' (\d+(?:\.\d+)*[A-Z]?)/', (string) $entry->labels->firstWhere('language', 'en')?->scope_note, $m) ? $m[1] : null);

            return $shown;
        })->filter()->values()->all());
    }

    /** The name type (name-type notation) an element's values are kept as, or null if it is not a name element. */
    public static function nameType(string $notation): ?string
    {
        return config('workspace.name_elements')[$notation] ?? null;
    }

    /** What a code element lists (language or script, config workspace.code_elements), or null if it is not one. */
    public static function codeKind(string $notation): ?string
    {
        return config('workspace.code_elements')[$notation] ?? null;
    }

    /** @return list<string> */
    public static function notations(string $standard): array
    {
        return self::of($standard)->pluck('notation')->all();
    }

    /**
     * Area headings of the two standards, keyed by area number. These are the
     * standards' own section titles, used only as headings on screen; which
     * area an element belongs to comes from the number in its scope note.
     */
    private const AREAS = [
        self::ISDIAH => ['5.1' => 'Identity area', '5.2' => 'Contact area', '5.3' => 'Description area', '5.4' => 'Access area', '5.5' => 'Services area', '5.6' => 'Control area'],
        self::ISAD_G => ['3.1' => 'Identity area', '3.2' => 'Context area', '3.3' => 'Content and structure area',
            '3.4' => 'Conditions of access and use area', '3.5' => 'Allied materials area', '3.6' => 'Notes area', '3.7' => 'Description control area'],
        self::ISAAR_CPF => ['5.1' => 'Identity area', '5.2' => 'Description area', '5.3' => 'Relationships area', '5.4' => 'Control area'],
        self::ACTIVITY => ['5.1' => 'Identity area', '5.2' => 'Context area'],
        // CAAIS 1.0's sections.
        self::ACCESSION => ['1' => 'Identity section', '2' => 'Source information section', '3' => 'Materials section', '4' => 'Management section',
            '5' => 'Event section', '6' => 'General section', '7' => 'Control section'],
        // The display standards' own headings, AtoM's; '' is the heading of the elements the standard does not number.
        'dacs' => ['2' => 'Identity elements', '3' => 'Content and structure elements', '4' => 'Conditions of access and use elements',
            '5' => 'Acquisition and appraisal elements', '6' => 'Related materials elements', '7' => 'Notes element', '8' => 'Description control element'],
        'rad' => ['1.1' => 'Title and statement of responsibility area', '1.2' => 'Edition area', '1.3' => 'Class of material specific details area',
            '1.4' => 'Dates of creation area', '1.5' => 'Physical description area', '1.6' => "Publisher's series area", '1.7' => 'Archival description area',
            '1.8' => 'Notes area', '1.9' => 'Standard number area', '' => 'Control area'],
    ];

    /** The element's number in its standard, such as 5.3.1 or 3.1.2, from its scope note; an AtoM field the standard does not number has its area's, such as 3.7. */
    public static function number(Concept $element): ?string
    {
        if (array_key_exists('shown_number', $element->getAttributes())) {
            return $element->getAttribute('shown_number');
        }
        $note = (string) $element->labels->firstWhere('language', 'en')?->scope_note;

        return preg_match('/(?:ISDIAH|ISAD\(G\)|ISAAR\(CPF\)|ISDF|CAAIS) (\d+\.\d+(?:\.\d+)?)/', $note, $m) ? $m[1] : null;
    }

    /**
     * The standard's elements grouped under their areas, in order.
     *
     * @return array<string, Collection<int, Concept>> area heading => elements
     */
    public static function byArea(string $standard): array
    {
        $areas = [];
        foreach (self::of($standard) as $element) {
            $number = self::number($element);
            if (in_array($standard, [...self::DISPLAY, self::ACCESSION], true)) {
                // DACS and CAAIS number their areas by chapter (2), RAD by rule (1.1, from 1.1B).
                $area = collect([preg_match('/^\d+\.\d+/', (string) $number, $m) ? $m[0] : null, strtok((string) $number, '.')])
                    ->first(fn ($candidate) => $candidate && isset(self::AREAS[$standard][$candidate]));
                $heading = $area ? $area.' '.__(self::AREAS[$standard][$area]) : __(self::AREAS[$standard][''] ?? 'Description');
                $areas[$heading] ??= new Collection;
                $areas[$heading]->push($element);

                continue;
            }
            $area = $number ? implode('.', array_slice(explode('.', $number), 0, 2)) : null;
            // An AtoM field a numbered standard has no element for (a repository's geographic subregion, say) is among
            // its access points, as AtoM shows it; a standard with no numbers has one area.
            $heading = $area ? ($area.' '.__(self::AREAS[$standard][$area] ?? '')) : (isset(self::AREAS[$standard]) ? __('Access points') : __('Description'));
            $areas[$heading] ??= new Collection;
            $areas[$heading]->push($element);
        }

        return $areas;
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
