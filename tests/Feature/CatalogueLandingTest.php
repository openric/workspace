<?php

// OpenRiC Workspace - the catalogue's home page as the landing page: staff land there after signing in, with the staff
// menus; its heading and the counts under the introduction are the institution's to set.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogueLandingTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://archives.example.org';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        $institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => self::SITE, 'catalogue_introduction' => 'Archival management, built around context.']);
        app(RoleService::class)->seedDefaults($institution);
        $this->admin = app(AccountService::class)->create($institution, 'Admin', 'admin@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->admin, 'administrator');
    }

    public function test_staff_land_on_the_catalogue_with_their_menus_and_visitors_see_it_as_before(): void
    {
        $this->post(self::SITE.'/sign-in', ['email' => 'admin@example.org', 'password' => 'correct horse battery staple'])->assertRedirect(route('discover.home'));
        $this->get(self::SITE.'/discover')->assertOk()->assertSee('The catalogue of Riverside Archives')->assertSee('Describe')->assertSee('Workflow')
            ->assertSee('Archival descriptions')->assertSee('Log out');
        auth()->logout();
        $this->get(self::SITE.'/discover')->assertOk()->assertSee('Log in')->assertDontSee('Log out')->assertDontSee('Import and export');
    }

    public function test_the_heading_and_counts_are_the_institutions_to_set(): void
    {
        $this->get(self::SITE.'/discover')->assertOk()->assertSee('archival descriptions, ', false)->assertSee('The catalogue of Riverside Archives');
        $this->actingAs($this->admin)->post('/settings', ['name' => 'Riverside Archives', 'catalogue_heading' => 'Welcome to the Riverside catalogue', 'catalogue_show_counts' => '0',
            'catalogue_introduction' => 'Archival management, built around context.'])->assertSessionHasNoErrors();
        auth()->logout();
        $this->get(self::SITE.'/discover')->assertOk()->assertSee('Welcome to the Riverside catalogue')->assertDontSee('The catalogue of Riverside Archives')
            ->assertDontSee('people, families and organisations')->assertSee('Archival management, built around context.');
    }
}
