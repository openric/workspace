<?php

// OpenRiC Workspace - application service provider.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Providers;

use App\Access\AccessControl;
use App\Models\DescriptionValue;
use App\Models\Identifier;
use App\Models\Name;
use App\Models\RicDate;
use App\Models\User;
use App\Search\SearchIndex;
use App\Support\Vocab;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One per request, so its cache of roles and concepts lives as long as the request.
        $this->app->scoped(AccessControl::class);
    }

    public function boot(): void
    {
        // The search index follows what entities say (ADR 0009): a change to a name, a value, an identifier or a date,
        // or to the entity itself, marks it; marked entities are indexed again once, when the request or command ends.
        foreach ([DescriptionValue::class, Name::class, Identifier::class, RicDate::class] as $part) {
            $part::saved(fn ($model) => SearchIndex::mark((string) $model->subject_table, (string) $model->subject_id));
            $part::deleted(fn ($model) => SearchIndex::mark((string) $model->subject_table, (string) $model->subject_id));
        }
        foreach (SearchIndex::KINDS as $kind) {
            $kind::saved(fn ($model) => SearchIndex::mark($model->getTable(), (string) $model->getKey()));
        }
        $this->app->terminating(fn () => app(SearchIndex::class)->flush());

        // Default deny: an ability that is a permission IRI is an administrative
        // permission (institution kind), decided by AccessControl (ADR 0005). Gate
        // denies every other ability unless a policy explicitly allows it.
        Gate::before(function (User $user, string $ability) {
            return str_starts_with($ability, Vocab::PERMISSIONS) ? $user->hasPermission($ability) : null;
        });

        // Sign-in attempts: five a minute for each email and address pair.
        RateLimiter::for('sign-in', fn (Request $request) => Limit::perMinute(5)
            ->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()));
    }
}
