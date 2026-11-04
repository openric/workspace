<?php

// OpenRiC Workspace - application service provider.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Providers;

use App\Access\AccessControl;
use App\Jobs\IdentifyFormat;
use App\Jobs\NormaliseFile;
use App\Mail\EmailAuditListener;
use App\Models\CustomFieldValue;
use App\Models\DescriptionValue;
use App\Models\DigitalFile;
use App\Models\Identifier;
use App\Models\Name;
use App\Models\RecordRedaction;
use App\Models\Relation;
use App\Models\RicDate;
use App\Models\User;
use App\Preservation\PreservationEvents;
use App\Privacy\Redaction;
use App\Research\Shop;
use App\Search\SearchIndex;
use App\Support\Vocab;
use App\Versions\Versions;
use App\Webhooks\Webhooks;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One per request, so its cache of roles and concepts lives as long as the request.
        $this->app->scoped(AccessControl::class);
        // And its cache of which fields of a record are redacted (phase 18b).
        $this->app->scoped(Redaction::class);
    }

    public function boot(): void
    {
        // The search index follows what entities say (ADR 0009): a change to a name, a value, an identifier or a date,
        // or to the entity itself, marks it; marked entities are indexed again once, when the request or command ends.
        foreach ([DescriptionValue::class, Name::class, Identifier::class, RicDate::class, CustomFieldValue::class] as $part) {
            $part::saved(fn ($model) => SearchIndex::mark((string) $model->subject_table, (string) $model->subject_id));
            $part::deleted(fn ($model) => SearchIndex::mark((string) $model->subject_table, (string) $model->subject_id));
        }
        foreach (SearchIndex::KINDS as $kind) {
            $kind::saved(fn ($model) => SearchIndex::mark($model->getTable(), (string) $model->getKey()));
        }
        // A field redacted, or no longer, changes what the public entry may hold (phase 18b).
        RecordRedaction::saved(fn ($model) => SearchIndex::mark('record_resources', (string) $model->record_resource_id));
        RecordRedaction::deleted(fn ($model) => SearchIndex::mark('record_resources', (string) $model->record_resource_id));
        $this->app->terminating(fn () => app(SearchIndex::class)->flush());
        // What is done to each file, as PREMIS events (phase 15a): kept (ingested or made from the master), and removed; its
        // format identified in the background once it is kept.
        DigitalFile::created(function (DigitalFile $file) {
            app(PreservationEvents::class)->kept($file);
            IdentifyFormat::dispatch($file->id)->afterCommit();
            // Normalised by the rules, after its format is known, where its institution chose that (phase 15c).
            if ($file->use?->notation === 'master' && DB::table('institutions')->where('id', $file->institution_id)->value('normalise_on_ingest')) {
                NormaliseFile::dispatch($file->id, auth()->id())->afterCommit();
            }
        });
        DigitalFile::deleted(fn (DigitalFile $file) => app(PreservationEvents::class)->removed($file));
        // Versions follow the same marks (roadmap phase 22b): a unit of description or authority record changed, or
        // anything of it or a relation it takes part in, gets one new version when the request, command or job ends.
        foreach ([DescriptionValue::class, Name::class, Identifier::class, RicDate::class, CustomFieldValue::class] as $part) {
            $part::saved(fn ($model) => Versions::mark($model->subject_table, $model->subject_id));
            $part::deleted(fn ($model) => Versions::mark($model->subject_table, $model->subject_id));
        }
        foreach (Versions::KINDS as $table => $kind) {
            $kind::saved(fn ($model) => Versions::mark($table, $model->getKey()));
        }
        foreach (['saved', 'deleted'] as $event) {
            Relation::$event(function ($r) {
                Versions::mark($r->source_table, $r->source_id);
                Versions::mark($r->target_table, $r->target_id);
            });
        }
        $this->app->terminating(fn () => app(Versions::class)->flush());
        // Webhooks follow the same marks (roadmap phase 22c): an entity created, changed or deleted, or anything of it.
        foreach ([DescriptionValue::class, Name::class, Identifier::class, RicDate::class, CustomFieldValue::class] as $part) {
            $part::saved(fn ($model) => Webhooks::mark('updated', $model->subject_table, $model->subject_id));
            $part::deleted(fn ($model) => Webhooks::mark('updated', $model->subject_table, $model->subject_id));
        }
        foreach (Webhooks::KINDS as $kind => $name) {
            $class = SearchIndex::KINDS[$name];
            $class::saved(fn ($model) => Webhooks::mark($model->wasRecentlyCreated ? 'created' : ($model->wasChanged('deleted_at') && $model->deleted_at ? 'deleted' : 'updated'),
                $model->getTable(), $model->getKey()));
        }
        $this->app->terminating(fn () => app(Webhooks::class)->flush());
        Queue::after(function () {
            app(Versions::class)->flush();
            app(Webhooks::class)->flush();
        });
        // A visitor's cart joins the account's on signing in (Heratio's mergeGuestCart; phase 19a).
        Event::listen(Login::class, fn (Login $login) => $login->user instanceof User && session()->has('cart_key')
            ? app(Shop::class)->merge($login->user, session('cart_key')) : null);

        // Every message sent is logged (roadmap phase 7; Heratio's EventServiceProvider wiring of EmailAuditListener).
        Event::listen(MessageSending::class, [EmailAuditListener::class, 'handleMessageSending']);
        Event::listen(MessageSent::class, [EmailAuditListener::class, 'handleMessageSent']);

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
