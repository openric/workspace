<?php

// OpenRiC Workspace - look and feel (roadmap phase 26): the dashboard, the footer an institution sets on the web, the colour of
// its public catalogue, and the top of a unit's page.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Models\Institution;
use App\Models\StaticPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LookAndFeelTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        $accounts = app(AccountService::class);
        $this->institution = Institution::create(['name' => 'Riverside Parish Council Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->admin = $accounts->create($this->institution, 'Dr Thandi Mokoena', 'admin@example.org', 'correct horse battery staple');
        $accounts->grantTemplateRole($this->admin, 'administrator');
        $accounts->grantTemplateRole($this->admin, 'editor');
    }

    public function test_the_home_page_is_a_dashboard_of_the_work_waiting(): void
    {
        $this->actingAs($this->admin)->get('/')->assertOk()
            ->assertSee('<h1 class="title"', false)->assertSee('Riverside Parish Council Archives')
            ->assertSee('My tasks')->assertSee('Recent changes')->assertSee('Your roles')->assertSee('New description')
            // The avatar is the initial of the name, not of the title before it.
            ->assertSee('<span aria-hidden="true">T</span>', false);
        auth()->logout();
        $this->get('/')->assertRedirect(route('discover.home'));
    }

    public function test_an_administrator_sets_the_footer_text_and_the_catalogue_colour_on_the_web(): void
    {
        $this->actingAs($this->admin)->get('/settings')->assertOk()->assertSee('Text in the footer')->assertSee('Colour of the public catalogue');
        $this->actingAs($this->admin)->post('/settings', ['name' => 'Riverside Archives', 'footer_text' => "12 Church Street\nOpen Monday to Friday", 'brand_colour' => '#1A4D2E'])
            ->assertRedirect(route('settings.edit'));
        $institution = $this->institution->fresh();
        $this->assertSame(["12 Church Street\nOpen Monday to Friday", '#1a4d2e'], [$institution->footer_text, $institution->brand_colour]);
        $this->actingAs($this->admin)->get('/')->assertSee('12 Church Street');
        $this->actingAs($this->admin)->post('/settings', ['name' => 'Riverside Archives', 'brand_colour' => 'red'])->assertSessionHasErrors('brand_colour');
        $this->actingAs($this->admin)->post('/settings', ['name' => 'Riverside Archives', 'brand_colour' => '', 'footer_text' => '']);
        $this->assertNull($this->institution->fresh()->brand_colour);
    }

    public function test_a_static_page_given_a_place_is_linked_in_the_footer_in_order(): void
    {
        $this->actingAs($this->admin)->post(route('site.pages.store'), ['slug' => 'visiting', 'title' => 'Visiting the reading room', 'language' => 'en', 'footer_position' => 5])->assertRedirect();
        $this->actingAs($this->admin)->post(route('site.pages.store'), ['slug' => 'privacy', 'title' => 'Privacy notice', 'language' => 'en', 'footer_position' => 2])->assertRedirect();
        $this->actingAs($this->admin)->post(route('site.pages.store'), ['slug' => 'hidden', 'title' => 'Not in the footer', 'language' => 'en'])->assertRedirect();
        $this->assertNull(StaticPage::where('slug', 'hidden')->first()->footer_position);
        // A privacy page has no route of its own, so the footer and the list of pages link it under /pages.
        $this->actingAs($this->admin)->get(route('site.pages'))->assertOk()->assertSee('/pages/privacy');
        preg_match('~<footer class="site-footer.*?</footer>~s', $this->actingAs($this->admin)->get('/')->getContent(), $footer);
        $this->assertMatchesRegularExpression('~Privacy notice.*Visiting the reading room~s', $footer[0]);
        $this->assertStringNotContainsString('Not in the footer', $footer[0]);
        $this->actingAs($this->admin)->post(route('site.pages.store'), ['slug' => 'x', 'title' => 'X', 'language' => 'en', 'footer_position' => 0])->assertSessionHasErrors('footer_position');
        // About, which the footer always listed, starts in it.
        $this->actingAs($this->admin)->post(route('site.pages.store'), ['slug' => 'about', 'title' => 'About us', 'language' => 'en'])->assertRedirect();
        $this->assertSame(1, StaticPage::where('slug', 'about')->first()->footer_position);
    }
}
