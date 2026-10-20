<?php

// OpenRiC Workspace - the elements of one ICA standard, from the description-element vocabulary.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Each element is a concept whose broader concept is its standard (isdiah,
// isad-g); the grouping is data, never a list in code.

namespace App\Description;

use App\Models\Concept;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Collection;

final class Elements
{
    public const ISDIAH = 'isdiah';

    public const ISAD_G = 'isad-g';

    /** @var array<string, Collection<int, Concept>> */
    private static array $cache = [];

    /** Current elements of the standard, in vocabulary order (concepts are created in file order; ids are time-ordered). */
    public static function of(string $standard): Collection
    {
        return self::$cache[$standard] ??= Concept::current()
            ->whereHas('broader', fn ($q) => $q->where('iri', Vocab::element($standard)))
            ->with('labels')->orderBy('id')->get();
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
        '5.1' => 'Identity', '5.2' => 'Contact', '5.3' => 'Description', '5.4' => 'Access',
        '5.5' => 'Services', '5.6' => 'Control',
        '3.1' => 'Identity', '3.2' => 'Context', '3.3' => 'Content and structure',
        '3.4' => 'Conditions of access and use', '3.5' => 'Allied materials', '3.6' => 'Notes', '3.7' => 'Description control',
    ];

    /** The element's number in its standard, such as 5.3.1 or 3.1.2, from its scope note. */
    public static function number(Concept $element): ?string
    {
        $note = (string) $element->labels->firstWhere('language', 'en')?->scope_note;

        return preg_match('/(?:ISDIAH|ISAD\(G\)) (\d+\.\d+\.\d+)/', $note, $m) ? $m[1] : null;
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
            $heading = $area ? ($area.' '.(self::AREAS[$area] ?? '')) : 'Other';
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
