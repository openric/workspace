<?php

/**
 * KeyPageController - Controller for Heratio
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
 * OpenRiC Workspace - ported from Heratio, packages/ahg-api/src/Controllers/KeyPageController.php
 * at commit 5100c0f92 (heratio-dev; governance/CODE_PROVENANCE.md, ADR 0007).
 * Changed: Workspace accounts by UUID; an administrator manages another account's keys from
 * that account's page, within the institution only; scopes are api-scope concepts; the
 * secret is shown once, from the session, and never stored.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Accounts\ApiKeyService;
use App\Models\ApiKey;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ApiKeyController extends Controller
{
    public function __construct(private ApiKeyService $keys) {}

    /** The signed-in account's own keys. */
    public function index(Request $request): View
    {
        return view('accounts.api-keys', ['account' => $request->user(), 'own' => true, ...$this->managerData($request->user())]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->issueFrom($request, $request->user(), redirect()->route('account.api-keys'));
    }

    public function revoke(Request $request, ApiKey $apiKey): RedirectResponse
    {
        abort_unless($apiKey->user_id === $request->user()->id, 404);
        $this->keys->revoke($apiKey, $request->user());

        return redirect()->route('account.api-keys')->with('status', 'The key is revoked. Anything using it is refused from now on.');
    }

    /** An administrator issues a key for another account of the institution. */
    public function storeFor(Request $request, User $account): RedirectResponse
    {
        abort_unless($account->institution_id === $request->user()->institution_id, 404);

        return $this->issueFrom($request, $account, redirect(route('accounts.show', $account).'#api-keys'));
    }

    public function revokeFor(Request $request, User $account, ApiKey $apiKey): RedirectResponse
    {
        abort_unless($account->institution_id === $request->user()->institution_id && $apiKey->user_id === $account->id, 404);
        $this->keys->revoke($apiKey, $request->user());

        return redirect(route('accounts.show', $account).'#api-keys')->with('status', 'The key is revoked. Anything using it is refused from now on.');
    }

    /** What the key manager needs for one account. */
    public function managerData(User $owner): array
    {
        return ['keys' => $this->keys->keysFor($owner), 'scopes' => $this->keys->allowedScopes($owner), 'newKey' => session('new_key')];
    }

    private function issueFrom(Request $request, User $owner, RedirectResponse $back): RedirectResponse
    {
        $allowed = $this->keys->allowedScopes($owner)->pluck('notation')->all();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'scopes' => ['nullable', 'array'],
            'scopes.*' => ['string', Rule::in($allowed)],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'daily_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ], ['scopes.*.in' => 'This account may not give a key the ":input" scope.']);

        [, $secret] = $this->keys->issue($owner, $data['name'], $data['scopes'] ?? ['read'],
            isset($data['expires_at']) ? CarbonImmutable::parse($data['expires_at'])->endOfDay() : null,
            isset($data['daily_limit']) ? (int) $data['daily_limit'] : null, $request->user());

        return $back->with('new_key', $secret);
    }
}
