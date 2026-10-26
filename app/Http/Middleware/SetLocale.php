<?php

/**
 * SetLocale - Middleware for Heratio
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * This file is part of Heratio.
 *
 * Heratio is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * OpenRiC Workspace - copied from Heratio, app/Http/Middleware/SetLocale.php at commit 4131171e4
 * (heratio-dev; governance/CODE_PROVENANCE.md, ROADMAP.md phase 1). Kept: the resolution order
 * (URL, session, cookie, the account's choice, Accept-Language, the default), the year-long cookie
 * for an explicit choice only, the Accept-Language parsing with q-values and region fallback, and
 * Content-Language on every response. Changed: the languages are config workspace.interface_languages
 * (BCP 47 tags) instead of AtoM's setting table; the URL parameter is lang, not sf_culture; the
 * account's choice is users.interface_language; hydrateUiLabels is not copied, because it reads
 * interface-label overrides from AtoM's setting tables, which the Workspace does not have.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        // Resolution order, as Heratio's (#675):
        //   1. URL param        ?lang=xx  (explicit override, also seeds cookie)
        //   2. Session           session('locale')
        //   3. Cookie            request->cookie('locale')  (365-day persistence)
        //   4. Account choice    users.interface_language
        //   5. Accept-Language   HTTP header
        //   6. Default           the first of workspace.interface_languages
        // The account's choice sits below URL, session and cookie: those express
        // in-session intent; the account's choice is the durable default for a
        // new device.
        $language = $request->query('lang');

        if (is_string($language) && self::supported($language)) {
            App::setLocale($language);
            session(['locale' => $language]);
            // Year-long cookie so URL-driven switches persist across sessions.
            Cookie::queue('locale', $language, 60 * 24 * 365, '/', null, true, false, false, 'lax');
        } elseif (($sessionLanguage = session('locale')) && self::supported($sessionLanguage)) {
            App::setLocale($sessionLanguage);
        } elseif (($cookieLanguage = $request->cookie('locale')) && self::supported($cookieLanguage)) {
            App::setLocale($cookieLanguage);
            session(['locale' => $cookieLanguage]);
        } elseif (($accountLanguage = $request->user()?->interface_language) && self::supported($accountLanguage)) {
            App::setLocale($accountLanguage);
            session(['locale' => $accountLanguage]);
        } elseif ($acceptLanguage = $this->resolveFromAcceptLanguage($request)) {
            // Not kept in a cookie: cookies are for explicit choices, so a browser
            // asking for de does not fix the device in German.
            App::setLocale($acceptLanguage);
            session(['locale' => $acceptLanguage]);
        } else {
            App::setLocale(array_key_first(config('workspace.interface_languages')));
        }

        $response = $next($request);

        // RFC 7231 3.1.3.2: the active language, so caches key on it and screen
        // readers pronounce the page in it.
        $response->headers->set('Content-Language', App::getLocale());

        return $response;
    }

    /** Does the interface speak this language (a key of workspace.interface_languages)? */
    public static function supported(mixed $language): bool
    {
        return is_string($language) && array_key_exists($language, config('workspace.interface_languages'));
    }

    /**
     * Parse the Accept-Language header and return the highest-q supported
     * language, or null when none matches. Handles RFC 7231 q-values:
     *   Accept-Language: af-ZA,af;q=0.9,en;q=0.8
     * picks af (af-ZA falls back to its primary tag af).
     */
    private function resolveFromAcceptLanguage(Request $request): ?string
    {
        $header = (string) $request->header('Accept-Language', '');
        if ($header === '') {
            return null;
        }

        $supportedLower = [];
        foreach (array_keys(config('workspace.interface_languages')) as $code) {
            $supportedLower[strtolower((string) $code)] = $code;
        }

        $parsed = [];
        foreach (explode(',', $header) as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            $parts = array_map('trim', explode(';', $token));
            $tag = strtolower(array_shift($parts));
            if ($tag === '' || $tag === '*') {
                continue;
            }
            $q = 1.0;
            foreach ($parts as $param) {
                if (stripos($param, 'q=') === 0) {
                    $candidate = (float) substr($param, 2);
                    if ($candidate >= 0.0 && $candidate <= 1.0) {
                        $q = $candidate;
                    }
                }
            }
            if ($q <= 0.0) {
                continue;
            }
            // Preserve original order for stable sort on tied q-values.
            $parsed[] = ['tag' => $tag, 'q' => $q, 'order' => count($parsed)];
        }

        usort($parsed, fn ($a, $b) => $a['q'] === $b['q'] ? $a['order'] <=> $b['order'] : $b['q'] <=> $a['q']);

        foreach ($parsed as $entry) {
            $tag = $entry['tag'];
            // Direct hit ("af" -> af), then the region-tag fallback ("af-ZA" -> af).
            if (isset($supportedLower[$tag])) {
                return $supportedLower[$tag];
            }
            $dash = strpos($tag, '-');
            if ($dash !== false && isset($supportedLower[substr($tag, 0, $dash)])) {
                return $supportedLower[substr($tag, 0, $dash)];
            }
        }

        return null;
    }
}
