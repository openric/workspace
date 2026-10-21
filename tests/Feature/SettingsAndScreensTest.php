<?php

// OpenRiC Workspace - institution settings, the main navigation and the description screens.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\RepositoryService;
use App\Models\Institution;
use App\Models\Repository;
use App\Models\User;
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
        app(RoleService::class)->seedDefaults($this->institution);
        $this->admin = $accounts->create($this->institution, 'The Administrator', 'admin@example.org', 'correct horse battery staple');
        $accounts->grantTemplateRole($this->admin, 'administrator');
        $accounts->grantTemplateRole($this->admin, 'editor');
    }

    public function test_an_administrator_sets_the_catalogue_introduction_and_credits_and_clears_them(): void
    {
        $this->actingAs($this->admin)->get('/settings')->assertOk()->assertSee('Introduction on the catalogue')->assertSee('Credits for data reused from others');
        $this->actingAs($this->admin)->post('/settings', ['name' => 'Riverside Archives', 'catalogue_introduction' => "  Welcome.  \n", 'catalogue_credits' => 'Data: Example (2026)'])
            ->assertRedirect(route('settings.edit'));
        $this->assertSame(['Welcome.', 'Data: Example (2026)'], [$this->institution->fresh()->catalogue_introduction, $this->institution->fresh()->catalogue_credits]);
        $this->actingAs($this->admin)->post('/settings', ['name' => 'Riverside Archives', 'catalogue_introduction' => ' ', 'catalogue_credits' => '']);
        $this->assertNull($this->institution->fresh()->catalogue_introduction);
        $this->assertNull($this->institution->fresh()->catalogue_credits);
        $this->actingAs($this->admin)->post('/settings', ['name' => 'Riverside Archives', 'catalogue_credits' => str_repeat('x', 5001)])->assertSessionHasErrors('catalogue_credits');
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
        app(AccountService::class)->grantTemplateRole($archivist, 'editor');
        $this->actingAs($archivist)->get('/settings')->assertForbidden();
        // An archivist has the working tools under Manage and no Admin menu.
        $this->actingAs($archivist)->get('/')->assertDontSee('Settings')->assertSee('Home')
            ->assertSee('>Manage</summary>', false)->assertSee('>Vocabularies<', false)->assertDontSee('>Admin</summary>', false);
    }

    public function test_every_page_has_the_main_navigation(): void
    {
        $this->actingAs($this->admin)->get('/repositories')->assertSee('aria-label="Main"', false)
            ->assertSee('</svg>Home<', false)->assertSee('>Settings<', false)->assertSee('aria-current="page"', false)
            // Grouped in submenus, and the group holding the page is marked.
            ->assertSeeInOrder(['<summary class="here">', '</svg>Describe</summary>', '>Repositories<', '</svg>Manage</summary>', '>Vocabularies<', '>Containers<', '>Imports<',
                '>Duplicate authority records<', '</svg>Admin</summary>', '>Accounts<', '>Audit log<', '>Settings<'], false);
    }

    public function test_a_new_repository_opens_its_full_description_form(): void
    {
        $response = $this->actingAs($this->admin)->post('/repositories', ['name' => 'Riverside Parish Council Archives', 'language' => 'en']);
        $repository = Repository::firstOrFail();
        $response->assertRedirect(route('repositories.edit', [$repository, 'language' => 'en']));
        $this->actingAs($this->admin)->get(route('repositories.edit', [$repository, 'language' => 'en']))
            ->assertSee('5.3 Description')->assertSee('5.3.1 History')->assertSee('5.4.1 Opening times');
    }

    public function test_the_view_page_shows_the_described_elements_by_area_and_leaves_out_the_rest(): void
    {
        $repository = app(RepositoryService::class)->create($this->institution, 'Riverside Parish Council Archives', 'en');
        $this->actingAs($this->admin)->get("/repositories/{$repository->id}")
            ->assertSee('<details class="area" open>', false)->assertSee('5.1.2 Authorised form of name')->assertSee('1 of ')
            ->assertDontSee('5.3.1 History')->assertDontSee('Not yet described')->assertDontSee('<h2>5.3 Description</h2>', false)
            ->assertSee('Edit description')->assertSee('Add a translation');
    }

    public function test_the_main_menu_items_carry_an_icon_hidden_from_screen_readers(): void
    {
        $page = $this->actingAs($this->admin)->get('/')->assertOk()->getContent();
        preg_match('~<nav aria-label="Main".*?</nav>~s', $page, $nav);
        $this->assertSame(5, substr_count($nav[0], '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'), 'Home, Describe, Manage, Admin and the public catalogue');
        $this->assertStringContainsString('</svg>Describe</summary>', $nav[0]);
    }
}
