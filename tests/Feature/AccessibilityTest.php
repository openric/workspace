<?php

// OpenRiC Workspace - what the browser checks rest on (roadmap R1 hardening): the demonstration dataset loads, marked
// synthetic, with its accounts, a closed unit and the rest published, and refuses to load twice; the accessibility
// statement is public and says what is and is not checked; every staff page offers a skip link before anything else.
// The WCAG 2.2 AA scans and the keyboard walkthrough themselves run in a browser (tests/browser, npx playwright test).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\RepositoryService;
use App\Models\Agent;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessibilityTest extends TestCase
{
    use RefreshDatabase;

    private string $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-a11y-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->store));
        parent::tearDown();
    }

    private function install(): Institution
    {
        $institution = Institution::create(['name' => 'Riverside Demonstration Archive (synthetic)', 'base_iri' => 'https://demo.example.org']);
        app(RoleService::class)->seedDefaults($institution);
        app(RepositoryService::class)->create($institution, 'Riverside Demonstration Archive (synthetic)', 'en');
        $admin = app(AccountService::class)->create($institution, 'Admin', 'admin@demo.invalid', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($admin, 'administrator');

        return $institution;
    }

    public function test_the_demonstration_dataset_loads_once_marked_synthetic(): void
    {
        $this->artisan('workspace:demo', ['--password' => 'demonstration-password'])->assertFailed();
        $this->install();
        $this->artisan('workspace:demo', ['--password' => 'short'])->assertExitCode(2);
        $this->artisan('workspace:demo', ['--password' => 'demonstration-password'])->assertSuccessful();
        $this->assertGreaterThanOrEqual(35, RecordResource::count());
        $this->assertSame(5, Agent::count());
        $this->assertTrue(RecordResource::all()->every(fn ($r) => preg_match('/synthetic|sinteties/i', (string) $r->described('title', ['en', 'af']))), 'every title says it is synthetic');
        $this->assertTrue(User::where('email', 'archivist@demo.invalid')->sole()->may('update', 'description'));
        $this->post('/sign-in', ['email' => 'reader@demo.invalid', 'password' => 'demonstration-password'])->assertRedirect();
        $this->artisan('workspace:demo', ['--password' => 'demonstration-password'])->assertFailed();

        auth()->logout();
        $this->get('/discover?q=minutes')->assertOk()->assertSee('Minutes, January to June 1952 (synthetic)');
        $closed = RecordResource::whereHas('names', fn ($q) => $q->where('value', 'like', 'Letter about a pauper burial%'))->sole();
        $this->get(route('discover.record', $closed->id))->assertDontSee('pauper burial');
    }

    public function test_the_accessibility_statement_is_public_and_honest(): void
    {
        $this->install();
        $this->get('/accessibility-statement')->assertOk()->assertSee('WCAG 2.2')->assertSee('partially conformant')
            ->assertSee('has not yet been reviewed by people using screen readers')->assertSee(route('feedback.create'));
        $this->get('/discover')->assertOk()->assertSee(route('accessibility.statement'));
    }

    public function test_every_staff_page_offers_a_skip_link_first(): void
    {
        $this->install();
        $html = $this->actingAs(User::where('email', 'admin@demo.invalid')->sole())->get('/records')->assertOk()->getContent();
        $body = substr($html, strpos($html, '<body'));
        $this->assertMatchesRegularExpression('~^<body[^>]*>\s*(\{\{--.*?--\}\}\s*)?<a class="skip" href="#content">~s', $body);
        $this->assertStringContainsString('<main id="content" tabindex="-1">', $html);
    }
}
