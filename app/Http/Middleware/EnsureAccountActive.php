<?php

// OpenRiC Workspace - sign out an account that was disabled during its session, and send one that must have two-factor
// sign-in and is past its days of grace to set it up before anything else (roadmap phase 12b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Middleware;

use App\Accounts\TwoFactor;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isDisabled()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('sign-in')->withErrors(['email' => 'This account has been disabled.']);
        }

        // Signed in through the institution's identity provider, whose own second factor counts.
        if ($request->user() && ! $request->session()->get('signed_in_with_provider') && ! $request->routeIs('two-factor.*', 'sign-out')
            && app(TwoFactor::class)->overdue($request->user())) {
            return redirect()->route('two-factor.show')->with('status', __('Your institution asks every account like yours to sign in with a second factor. Set it up to go on.'));
        }

        return $next($request);
    }
}
