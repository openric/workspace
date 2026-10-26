<?php

/**
 * ApiAuthenticate and ApiRateLimit - Middleware for Heratio
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
 * OpenRiC Workspace - ported from Heratio, packages/ahg-api/src/Middleware/ApiAuthenticate.php
 * and ApiRateLimit.php at commit 5100c0f92 (heratio-dev; governance/CODE_PROVENANCE.md, ADR 0007).
 * Changed: one middleware for both; a key is optional on reads, which see what the Public role
 * sees with or without one, and required where the route names a scope; a key works only on
 * its own institution's host; scopes are cut to what the key's account holds now; errors are
 * RFC 7807 problem details; no session sign-in on the API; requests are counted per key and
 * hour in api_key_usage, in one statement, so two requests at once cannot both slip under a limit;
 * no X-RateLimit headers, which the per-address throttle around the API already sets.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Accounts\ApiKeyService;
use App\Api\Problem;
use App\Models\ApiKey;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function __construct(private ApiKeyService $keys) {}

    /** @param  string  ...$required  api-scope notations the route needs; none: a key is optional */
    public function handle(Request $request, Closure $next, string ...$required): Response
    {
        $secret = $request->header('X-API-Key') ?? $request->bearerToken();
        if ($secret === null || $secret === '') {
            return $required === [] ? $next($request)
                : Problem::response($request, 401, 'This needs an API key: send it in the X-API-Key header, or as Authorization: Bearer.');
        }

        $key = $this->keys->resolve($secret);
        if ($key === null) {
            return Problem::response($request, 401, 'The API key is not valid, or it has expired or been revoked.');
        }
        $institution = $request->attributes->get('institution');
        if ($institution !== null && $key->institution_id !== $institution->id) {
            return Problem::response($request, 403, 'This key belongs to another institution.');
        }
        $scopes = $this->keys->effectiveScopes($key);
        foreach ($required as $scope) {
            if (! in_array($scope, $scopes, true)) {
                return Problem::response($request, 403, $scope === 'write'
                    ? 'This key may not propose changes. Proposing needs the key of an account that may create or change descriptions or authority records, with the write scope.'
                    : "This key does not carry the {$scope} scope.");
            }
        }

        if ($refused = $this->overLimit($request, $key)) {
            return $refused;
        }
        $key->forceFill(['last_used_at' => now()])->saveQuietly();
        $request->attributes->add(['api_key' => $key, 'api_user' => $key->user, 'api_scopes' => $scopes]);

        return $next($request);
    }

    /** Count the request, unless the key's hourly or daily limit is used up: then 429 with Retry-After. */
    private function overLimit(Request $request, ApiKey $key): ?Response
    {
        $hour = $this->hour();
        if ($key->daily_limit !== null) {
            $today = (int) DB::table('api_key_usage')->where('api_key_id', $key->id)->where('hour', '>=', $hour->startOfDay())->sum('requests');
            if ($today >= $key->daily_limit) {
                return $this->tooMany($request, "This key's daily limit of {$key->daily_limit} requests is used up; it resets at midnight UTC.", $hour->startOfDay()->addDay());
            }
        }
        // Count first, then compare: the count each request sees includes itself.
        $counted = (int) DB::scalar(
            'INSERT INTO api_key_usage (api_key_id, hour, requests) VALUES (?, ?, 1)
             ON CONFLICT (api_key_id, hour) DO UPDATE SET requests = api_key_usage.requests + 1 RETURNING requests',
            [$key->id, $hour]);
        if ($counted > $key->hourly_limit) {
            return $this->tooMany($request, "This key's limit of {$key->hourly_limit} requests an hour is used up.", $hour->addHour());
        }

        return null;
    }

    private function tooMany(Request $request, string $detail, CarbonImmutable $resetsAt): Response
    {
        return Problem::response($request, 429, $detail, ['Retry-After' => (string) max(1, $resetsAt->getTimestamp() - time())]);
    }

    private function hour(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->startOfHour();
    }
}
