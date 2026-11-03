<?php

// OpenRiC Workspace - signing in and out.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Accounts\TwoFactor;
use App\Audit\AuditLog;
use App\Models\SignInProvider;
use App\Models\User;
use App\Support\HostInstitution;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SessionController extends Controller
{
    public function create(Request $request): View
    {
        // The institution's identity providers, each a "Sign in with" button (phase 12b).
        $institution = HostInstitution::for($request);

        return view('auth.sign-in', ['providers' => $institution ? SignInProvider::where(['institution_id' => $institution->id, 'is_active' => true])->orderBy('label')->get() : collect()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1000'],
        ]);
        $credentials['email'] = mb_strtolower(trim($credentials['email']));

        // A disabled account cannot sign in: disabled_at must be null.
        if (! Auth::attempt([...$credentials, 'disabled_at' => null])) {
            $known = User::where('email', $credentials['email'])->first();
            AuditLog::record(Vocab::action('sign-in-failed'), $known, null, ['email' => $credentials['email']], null, $known?->institution_id);
            throw ValidationException::withMessages(['email' => __('The email or password is not correct, or the account is disabled.')]);
        }

        $request->session()->regenerate();
        // With two-factor sign-in on, the password is half: the code comes next, and only then is the account signed in (phase 12b).
        $user = $request->user();
        if (app(TwoFactor::class)->enabled($user)) {
            Auth::logout();
            $request->session()->put('two_factor.pending', ['id' => $user->id, 'at' => now()->getTimestamp()]);

            return redirect()->route('two-factor.challenge');
        }
        AuditLog::record(Vocab::action('sign-in'), $request->user());

        // The public catalogue, with the staff menus; a page asked for before signing in comes first.
        return redirect()->intended(route('discover.home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        AuditLog::record(Vocab::action('sign-out'), $request->user());
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Back to the public catalogue, where a signed-out reader is.
        return redirect()->route('discover.home');
    }
}
