<?php

// OpenRiC Workspace - check a BCP 47 language tag.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Description;

final class Bcp47
{
    /**
     * Well-formed per the RFC 5646 syntax for the common case: a 2 or 3 letter
     * language, then optional script, region and variant subtags (en, af,
     * zh-Hant-TW, sr-Latn). ponytail: syntax only, not the IANA registry; check
     * against the registry when the language picker lists languages.
     */
    public static function valid(string $tag): bool
    {
        return strlen($tag) <= 35 && preg_match('/^[a-z]{2,3}(-[A-Z][a-z]{3})?(-([A-Z]{2}|[0-9]{3}))?(-([a-z0-9]{5,8}|[0-9][a-z0-9]{3}))*$/', $tag) === 1;
    }
}
