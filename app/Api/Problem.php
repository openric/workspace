<?php

// OpenRiC Workspace - errors of the API as RFC 7807 problem details (Core Discovery 0.6.0, section 4).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class Problem
{
    /** The registered OpenRiC error types by HTTP status; any other status takes one under the Workspace's own prefix. */
    private const TYPES = [400 => 'bad-request', 401 => 'authentication-required', 403 => 'forbidden', 404 => 'not-found', 409 => 'conflict',
        413 => 'payload-too-large', 415 => 'unsupported-media-type', 422 => 'validation-failed', 500 => 'internal-error'];

    private const TITLES = [400 => 'Bad Request', 401 => 'Authentication Required', 403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed',
        409 => 'Conflict', 413 => 'Payload Too Large', 415 => 'Unsupported Media Type', 422 => 'Validation Failed', 429 => 'Too Many Requests', 500 => 'Internal Error'];

    public static function response(Request $request, int $status, string $detail, array $headers = []): JsonResponse
    {
        $type = isset(self::TYPES[$status]) ? 'https://openric.org/errors/'.self::TYPES[$status]
            : 'https://workspace.openric.org/errors/'.str_replace(' ', '-', mb_strtolower(self::TITLES[$status] ?? 'error'));

        return response()->json(['type' => $type, 'title' => self::TITLES[$status] ?? 'Error', 'status' => $status, 'detail' => $detail, 'instance' => '/'.$request->path()],
            $status, ['Content-Type' => 'application/problem+json', 'Access-Control-Allow-Origin' => '*', 'Vary' => 'Accept', ...$headers],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
