<?php

// OpenRiC Workspace - signing in and out.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Audit\AuditLog;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SessionController extends Controller
{
    public function create(): View
    {
        return view('auth.sign-in');
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
