<?php

// OpenRiC Workspace - webhooks (roadmap phase 22c): only public addresses, a delivery signed and holding no content,
// one per entity and event, a publish told as published and not also as changed, and failures tried again on
// Heratio's schedule until the webhook is left alone.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Institution;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Vocabulary\SkosImporter;
use App\Webhooks\Webhooks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhooksTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private Institution $institution;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->officer = app(AccountService::class)->create($this->institution, 'Officer', 'officer@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->officer, 'administrator');
    }

    public function test_only_public_addresses(): void
    {
        foreach (['http://127.0.0.1/hook', 'https://10.0.0.5/hook', 'http://[::1]/hook', 'ftp://hooks.example.org/x', 'https://user:pw@8.8.8.8/x'] as $url) {
            $this->assertFalse(Webhooks::publicUrl($url), $url);
        }
        $this->assertTrue(Webhooks::publicUrl('https://8.8.8.8/hook'));
        $this->actingAs($this->officer)->post(route('webhooks.store'), ['name' => 'Inside', 'url' => 'http://localhost/hook', 'events' => ['created'], 'entity_kinds' => ['records']])
            ->assertSessionHasErrors('webhooks');
    }

    public function test_deliveries_are_signed_hold_no_content_and_are_tried_again(): void
    {
        config(['workspace.webhooks_check_hosts' => false]);
        $status = 200;
        Http::fake(['hooks.example.org/*' => function () use (&$status) {
            return Http::response($status === 200 ? 'ok' : 'down', $status);
        }]);
        $this->actingAs($this->officer)->post(route('webhooks.store'), ['name' => 'Catalogue mirror', 'url' => 'https://hooks.example.org/openric',
            'events' => ['created', 'updated', 'published'], 'entity_kinds' => ['records'], 'is_active' => 1])->assertSessionHas('secret');
        $secret = session('secret');
        $hook = Webhook::sole();

        $records = app(RecordService::class);
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $fonds = $records->create($this->institution, self::LEVEL.'fonds', 'Council records', 'en', null, $holder, 'C');
        $records->describe($fonds, ['scope-and-content' => 'Confidential minutes of the council.'], 'en');
        app(Webhooks::class)->flush();
        // Created and described in one go: told once, as created.
        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $r) use ($fonds, $secret) {
            $body = $r->body();

            return $r->url() === 'https://hooks.example.org/openric' && $r['event'] === 'created' && $r['iri'] === $fonds->iri && $r['kind'] === 'records'
                && $r->header('X-OpenRiC-Signature')[0] === 'sha256='.hash_hmac('sha256', $body, $secret) && ! str_contains($body, 'Confidential');
        });

        // Published: told as published, not also as changed.
        app(PublicationService::class)->apply($fonds, 'publish', null, false, $this->officer);
        app(Webhooks::class)->flush();
        $this->assertSame(['created', 'published'], WebhookDelivery::orderBy('created_at')->with('event')->get()->map(fn ($d) => $d->event->notation)->all());

        // A receiver that fails is tried again on Heratio's schedule, and after five failures the webhook is left alone.
        $status = 503;
        $records->describe($fonds, ['scope-and-content' => 'Minutes.'], 'en');
        app(Webhooks::class)->flush();
        $failing = WebhookDelivery::latest('created_at')->get()->first(fn ($d) => $d->event->notation === 'updated');
        $this->assertSame(['retrying', 1, 503], [$failing->status->notation, $failing->attempt_count, $failing->response_code]);
        $this->assertNotNull($failing->next_retry_at);
        for ($i = 0; $i < 4; $i++) {
            $this->travel(20)->minutes();
            app(Webhooks::class)->retryDue();
        }
        $failing = $failing->fresh();
        $this->assertSame(['failed', 5], [$failing->status->notation, $failing->attempt_count]);
        $this->assertSame(1, $hook->fresh()->failure_count);
        // The secret is shown once, on the page after it was made, and never again.
        $this->actingAs($this->officer)->get(route('webhooks.index'))->assertOk()->assertSee($secret);
        $this->actingAs($this->officer)->get(route('webhooks.index'))->assertOk()->assertSee('Catalogue mirror')->assertDontSee($secret);
    }
}
