<?php

// OpenRiC Workspace - languages (ISO 639) and scripts (ISO 15924) to choose from, named in the interface language,
// as AtoM's language and script pick lists name them (sfCultureInfo there; ICU's data, through PHP's intl, here).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Description;

use Locale;
use ResourceBundle;

final class IsoCodes
{
    /** @var array<string, array<string, string>> kind and locale => code => name */
    private static array $cache = [];

    /** @return array<string, string> code => name in the interface language, in the order of the names */
    public static function choices(string $kind): array
    {
        $locale = app()->getLocale();

        return self::$cache[$kind.'|'.$locale] ??= (function () use ($kind, $locale) {
            $codes = iterator_to_array(ResourceBundle::create('en', 'ICUDATA-lang')->get($kind === 'script' ? 'Scripts' : 'Languages'));
            $names = [];
            foreach (array_keys($codes) as $code) {
                // ISO 639 codes are two or three letters, ISO 15924 four; ICU also lists regional and variant forms, left out here.
                if ($kind === 'script' ? preg_match('/^[A-Z][a-z]{3}$/', $code) : preg_match('/^[a-z]{2,3}$/', $code)) {
                    $names[$code] = self::name($kind, $code, $locale);
                }
            }
            asort($names, SORT_LOCALE_STRING | SORT_FLAG_CASE);

            return $names;
        })();
    }

    public static function valid(string $kind, string $code): bool
    {
        return array_key_exists($code, self::choices($kind));
    }

    public static function name(string $kind, string $code, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $name = $kind === 'script' ? Locale::getDisplayScript('und-'.$code, $locale) : Locale::getDisplayLanguage($code, $locale);

        return $name !== '' && $name !== $code ? $name : $code;
    }

    /** The names of the codes a value holds (separated by spaces), joined for reading. */
    public static function names(string $kind, string $value): string
    {
        return implode(', ', array_map(fn ($code) => self::name($kind, $code), preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY)));
    }
}
