<?php

// OpenRiC Workspace - the languages the interface can speak, and those an institution offers (WS-005).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Nothing here lists a language. One can be offered when it has a translation file,
// lang/<BCP 47 tag>.json, and English always (the text the code is written in). An
// institution chooses which of them to offer under Settings, as AtoM's and Heratio's
// i18n languages setting does. A language is named in itself: from lang/languages.json
// where it says so (CLDR names some South African languages only in English), and
// otherwise by PHP's intl. The names in lang/languages.json are Heratio's (its culture switcher).

namespace App\Support;

use App\Models\Institution;
use Illuminate\Http\Request;
use Locale;

final class InterfaceLanguages
{
    /** @var list<string>|null */
    private static ?array $available = null;

    /** @var array<string, string>|null */
    private static ?array $names = null;

    /**
     * The languages the interface can speak: English, and each with a translation file.
     *
     * @return list<string>
     */
    public static function available(): array
    {
        if (self::$available === null) {
            $tags = array_map(fn ($path) => basename($path, '.json'), glob(lang_path('*.json')) ?: []);
            $tags = array_filter($tags, fn ($tag) => $tag !== 'languages');
            sort($tags);
            self::$available = array_values(array_unique(['en', ...$tags]));
        }

        return self::$available;
    }

    /**
     * The languages the institution offers, in the order of available(); English always.
     *
     * @return list<string>
     */
    public static function offered(?Institution $institution): array
    {
        $chosen = $institution?->interface_languages ?? ['en'];

        return array_values(array_filter(self::available(), fn ($tag) => $tag === 'en' || in_array($tag, $chosen, true)));
    }

    /** The institution whose languages a request is answered in: the host's, else the signed-in account's. */
    public static function institutionFor(Request $request): ?Institution
    {
        return HostInstitution::for($request) ?? $request->user()?->institution;
    }

    /** The language's name in itself, such as isiZulu or Sesotho. */
    public static function name(string $tag): string
    {
        self::$names ??= is_file(lang_path('languages.json')) ? (json_decode(file_get_contents(lang_path('languages.json')), true) ?: []) : [];
        if (isset(self::$names[$tag])) {
            return self::$names[$tag]; // as the language writes it: isiZulu, not IsiZulu
        }
        $name = class_exists(Locale::class) ? Locale::getDisplayName($tag, $tag) : $tag;

        return mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1);
    }

    /** For tests, after a translation file is added or removed. */
    public static function flush(): void
    {
        self::$available = self::$names = null;
    }
}
