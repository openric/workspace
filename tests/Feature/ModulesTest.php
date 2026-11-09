<?php

// OpenRiC Workspace - which modules an installation offers (roadmap R1 hardening, external audit of 8 November 2026,
// finding F08): with none named, an experimental module's addresses answer 404 and leave the menus and the pages that
// link to them, while the supported ones are untouched; naming a module, or "all", brings it back; every module's
// patterns name real routes, and none takes a supported one.
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
use App\Support\Modules;
use App\Support\Vocab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class ModulesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $record;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        $institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://riverside.example.org']);
        app(RoleService::class)->seedDefaults($institution);
        $this->admin = app(AccountService::class)->create($institution, 'Admin', 'admin@riverside.example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->admin, 'administrator');
        $holder = app(RepositoryService::class)->create($institution, 'Riverside Archives', 'en', 'RA');
        $fonds = app(RecordService::class)->create($institution, Vocab::BASE.'level-of-description/fonds', 'Minutes of the council', 'en', null, $holder, 'RPC');
        foreach ([$holder, $fonds] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->admin);
        }
        $this->record = $fonds->id;
    }

    public function test_with_none_named_the_experimental_modules_are_gone_and_the_supported_ones_stay(): void
    {
        config(['workspace.modules' => '']);
        $this->assertSame([], Modules::enabled());
        $this->actingAs($this->admin)->get(route('shop.orders'))->assertNotFound();
        $this->actingAs($this->admin)->get(route('reading-room.desk'))->assertNotFound();
        $this->actingAs($this->admin)->get(route('webhooks.index'))->assertNotFound();
        $this->get(route('discover.research-leads'))->assertNotFound();

        $this->actingAs($this->admin)->get(route('records.index'))->assertOk()->assertDontSee(route('reading-room.desk'))->assertDontSee(route('shop.orders'))
            ->assertSee(route('accessions.index'));
        $this->actingAs($this->admin)->get(route('records.show', $this->record))->assertOk()->assertDontSee(route('research.studio', ['records', $this->record]))->assertDontSee(route('research.rights-policies', $this->record));
        $this->get(route('discover.record', $this->record))->assertOk()->assertDontSee(route('discover.transcribe', $this->record));
        $this->get(route('discover.ways-in'))->assertOk()->assertDontSee(route('discover.languages'));
    }

    public function test_a_module_named_or_all_is_offered(): void
    {
        config(['workspace.modules' => 'shop, Webhooks']);
        $this->assertSame(['shop', 'webhooks'], Modules::enabled());
        $this->actingAs($this->admin)->get(route('shop.orders'))->assertOk();
        $this->actingAs($this->admin)->get(route('reading-room.desk'))->assertNotFound();

        config(['workspace.modules' => 'dois']);
        $this->actingAs($this->admin)->get(route('research.services.settings'))->assertOk();
        $this->actingAs($this->admin)->get(route('research.profile'))->assertNotFound();

        config(['workspace.modules' => 'all']);
        $this->actingAs($this->admin)->get(route('reading-room.desk'))->assertOk();
        $this->get(route('discover.record', $this->record))->assertSee(route('discover.transcribe', $this->record));
    }

    public function test_every_module_names_real_routes_and_no_supported_one(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())->map->getName()->filter()->values();
        foreach (Modules::EXPERIMENTAL as $module => $patterns) {
            foreach ($patterns as $pattern) {
                $this->assertTrue($names->contains(fn ($n) => Str::is($pattern, $n)), "$module: $pattern names no route");
            }
        }
        foreach (['records.show', 'agents.show', 'accessions.index', 'discover.record', 'discover.home', 'api.oai', 'site.page', 'site.landing-page', 'clearance.request', 'imports.index', 'iiif.manifest'] as $supported) {
            $this->assertSame([], Modules::of($supported), "$supported is supported");
        }
    }
}
