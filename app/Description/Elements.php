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

    /** @var array<string, Collection<int, Concept>> */
    private static array $cache = [];

    /** Current elements of the standard, in the order of their numbers in it (an element added later still takes its place). */
    public static function of(string $standard): Collection
    {
        return self::$cache[$standard] ??= Concept::current()
            ->whereHas('broader', fn ($q) => $q->where('iri', Vocab::element($standard)))
            ->with('labels')->orderBy('id')->get()
            ->sort(fn (Concept $a, Concept $b) => version_compare(self::number($a) ?? '9', self::number($b) ?? '9') ?: strcmp($a->id, $b->id))->values();
    }

    /** The name type (name-type notation) an element's values are kept as, or null if it is not a name element. */
    public static function nameType(string $notation): ?string
    {
        return config('workspace.name_elements')[$notation] ?? null;
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
    ];

    /** The element's number in its standard, such as 5.3.1 or 3.1.2, from its scope note. */
    public static function number(Concept $element): ?string
    {
        $note = (string) $element->labels->firstWhere('language', 'en')?->scope_note;

        return preg_match('/(?:ISDIAH|ISAD\(G\)|ISAAR\(CPF\)|ISDF) (\d+\.\d+\.\d+)/', $note, $m) ? $m[1] : null;
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
            $area = $number ? implode('.', array_slice(explode('.', $number), 0, 2)) : null;
            $heading = $area ? ($area.' '.__(self::AREAS[$standard][$area] ?? '')) : __('Description');
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
