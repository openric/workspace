<?php

// OpenRiC Workspace - institution settings, the main navigation and the description screens.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Accounts\AccountService;
use App\Description\RepositoryService;
use App\Models\Institution;
use App\Models\Repository;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SettingsAndScreensTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->loadBundledVocabularies();
        $accounts = app(AccountService::class);
        $this->institution = Institution::create(['name' => 'Riverside Parish Council Archives', 'base_iri' => 'https://archives.example.org']);
        $accounts->seedDefaultGrants($this->institution);
        $this->admin = $accounts->create($this->institution, 'The Administrator', 'admin@example.org', 'correct horse battery staple');
        $accounts->grantRole($this->admin, Vocab::role('administrator'));
        $accounts->grantRole($this->admin, Vocab::role('archivist'));
    }

    public function test_an_administrator_changes_the_name_header_and_logo(): void
    {
        $this->actingAs($this->admin)->post('/settings', [
            'name' => 'Riverside Archives', 'header_title' => 'Riverside Archives Workspace',
            'logo' => UploadedFile::fake()->image('logo.png', 200, 60),
        ])->assertRedirect(route('settings.edit'));

        $institution = $this->institution->fresh();
        $this->assertSame('Riverside Archives', $institution->name);
        Storage::disk('local')->assertExists($institution->logo_path);
        $this->get('/branding/logo')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs($this->admin)->get('/')->assertSee('Riverside Archives Workspace')->assertSee(route('branding.logo'), false);
        $this->post('/sign-out');
        $this->get('/sign-in')->assertSee('Riverside Archives Workspace');

        $old = $institution->logo_path;
        $this->actingAs($this->admin)->post('/settings', ['name' => 'Riverside Archives', 'remove_logo' => '1']);
        Storage::disk('local')->assertMissing($old);
        $this->get('/branding/logo')->assertNotFound();
    }

    public function test_an_svg_or_oversized_logo_is_refused(): void
    {
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->actingAs($this->admin)->post('/settings', ['name' => 'X', 'logo' => $svg])->assertSessionHasErrors('logo');
        $big = UploadedFile::fake()->image('big.png')->size(2048);
        $this->actingAs($this->admin)->post('/settings', ['name' => 'X', 'logo' => $big])->assertSessionHasErrors('logo');
        $this->assertNull($this->institution->fresh()->logo_path);
    }

    public function test_settings_need_manage_institution(): void
    {
        $archivist = app(AccountService::class)->create($this->institution, 'An Archivist', 'archivist@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantRole($archivist, Vocab::role('archivist'));
        $this->actingAs($archivist)->get('/settings')->assertForbidden();
        $this->actingAs($archivist)->get('/')->assertDontSee('Settings')->assertSee('Home');
    }

    public function test_every_page_has_the_main_navigation(): void
    {
        $this->actingAs($this->admin)->get('/repositories')->assertSee('aria-label="Main"', false)
            ->assertSee('>Home<', false)->assertSee('>Settings<', false)->assertSee('aria-current="page"', false);
    }

    public function test_a_new_repository_opens_its_full_description_form(): void
    {
        $response = $this->actingAs($this->admin)->post('/repositories', ['name' => 'Riverside Parish Council Archives', 'language' => 'en']);
        $repository = Repository::firstOrFail();
        $response->assertRedirect(route('repositories.edit', [$repository, 'language' => 'en']));
        $this->actingAs($this->admin)->get(route('repositories.edit', [$repository, 'language' => 'en']))
            ->assertSee('5.3 Description')->assertSee('5.3.1 History')->assertSee('5.4.1 Opening times');
    }

    public function test_the_view_page_shows_every_element_and_what_is_missing(): void
    {
        $repository = app(RepositoryService::class)->create($this->institution, 'Riverside Parish Council Archives', 'en');
        $this->actingAs($this->admin)->get("/repositories/{$repository->id}")
            ->assertSee('5.1.2 Authorised form of name')->assertSee('5.3.1 History')->assertSee('Not yet described')
            ->assertSee('Edit description')->assertSee('Add a translation');
    }
}
