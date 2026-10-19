<?php

// OpenRiC Workspace - application service provider.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Providers;

use App\Models\User;
use App\Support\Vocab;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Default deny: an ability that is a permission IRI is allowed only if one of
        // the account's roles is granted it (role_grants). Gate denies every other
        // ability unless a policy explicitly allows it.
        Gate::before(function (User $user, string $ability) {
            return str_starts_with($ability, Vocab::PERMISSIONS) ? $user->hasPermission($ability) : null;
        });

        // Sign-in attempts: five a minute for each email and address pair.
        RateLimiter::for('sign-in', fn (Request $request) => Limit::perMinute(5)
            ->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()));
    }
}
