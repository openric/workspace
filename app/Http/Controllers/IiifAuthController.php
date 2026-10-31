<?php

// OpenRiC Workspace - the services of IIIF Authorization Flow 2.0 (roadmap phase 14b, ADR 0014), after Heratio's
// IiifAuthFlow2Controller: the probe a viewer asks with its token, the access service a viewer opens in a window to
// sign in, and the token service it loads in a frame, which posts the token to the viewer's window.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Iiif\Auth;
use App\Models\DigitalFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class IiifAuthController extends Controller
{
    public function __construct(private Auth $auth) {}

    /** The probe: a viewer sends the token it was given as a bearer token, from any site. */
    public function probe(Request $request, DigitalFile $file): JsonResponse|Response
    {
        $headers = ['Access-Control-Allow-Origin' => '*', 'Access-Control-Allow-Headers' => 'Authorization', 'Access-Control-Allow-Methods' => 'GET, OPTIONS'];
        if ($request->isMethod('OPTIONS')) {
            return response('', 204, $headers);
        }

        return response()->json($this->auth->probe($file, $this->auth->user($request->bearerToken())), 200, ['Content-Type' => 'application/ld+json', ...$headers],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** The access service, active profile: sign in if need be, then the window closes itself. */
    public function access(Request $request): RedirectResponse|Response
    {
        if ($request->user() === null) {
            return redirect()->guest(route('sign-in'));
        }

        return response('<!doctype html><title>'.e(__('Signed in')).'</title><p>'.e(__('Signed in. This window closes itself.')).'</p><script>window.close()</script>', 200,
            ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** The token service: a page loaded in a frame that posts the token, or why there is none, to the viewer's window. */
    public function token(Request $request): Response
    {
        $origin = (string) $request->query('origin', '');
        $messageId = (string) $request->query('messageId', '');
        $message = $request->user()
            ? ['@context' => Auth::CONTEXT, 'type' => 'AuthAccessToken2', 'accessToken' => $this->auth->issue($request->user()), 'expiresIn' => Auth::TOKEN_SECONDS, 'messageId' => $messageId]
            : ['@context' => Auth::CONTEXT, 'type' => 'AuthAccessTokenError2', 'profile' => 'missingAspect', 'heading' => ['en' => ['Not signed in']], 'messageId' => $messageId];
        // Only to the window that asked, by its origin; nothing when the origin is not one.
        $target = preg_match('~^https?://[^/\s]+$~', $origin) ? $origin : null;
        $script = $target ? 'window.parent.postMessage('.json_encode($message, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP).', '.json_encode($target, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG).');' : '';

        return response('<!doctype html><title>IIIF</title><script>'.$script.'</script>', 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store']);
    }
}
