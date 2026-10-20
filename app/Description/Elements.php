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

    public static function flush(): void
    {
        self::$cache = [];
    }
}
