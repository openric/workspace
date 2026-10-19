<?php

// OpenRiC Workspace - set a password from a one-time link, or change your own.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Accounts\AccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordController extends Controller
{
    public function __construct(private AccountService $accounts) {}

    public function createFromLink(Request $request, string $token): View
    {
        return view('auth.set-password', ['token' => $token, 'email' => (string) $request->query('email')]);
    }

    public function storeFromLink(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)],
        ]);
        if (! $this->accounts->setPasswordWithToken($data['email'], $data['token'], $data['password'])) {
            throw ValidationException::withMessages(['password' => 'This link is not valid or has expired. Ask an administrator for a new one.']);
        }

        return redirect()->route('sign-in')->with('status', 'Your password is set. You can sign in now.');
    }

    public function edit(): View
    {
        return view('auth.change-password');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)],
        ]);
        if (! Hash::check($data['current_password'], $request->user()->password)) {
            throw ValidationException::withMessages(['current_password' => 'That is not your current password.']);
        }
        $this->accounts->changePassword($request->user(), $data['password']);

        return redirect()->route('home')->with('status', 'Your password is changed.');
    }
}
