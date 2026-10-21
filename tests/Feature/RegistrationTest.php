<?php

// OpenRiC Workspace - visitors ask for an account and an administrator decides (Heratio's registration, without email verification).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Models\Institution;
use App\Models\RegistrationRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        $this->institution = Institution::create(['name' => 'Riverside Parish Council Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $accounts = app(AccountService::class);
        $this->admin = $accounts->create($this->institution, 'The Administrator', 'admin@example.org', 'correct horse battery staple');
        $accounts->grantTemplateRole($this->admin, 'administrator');
    }

    private function ask(array $fields = []): TestResponse
    {
        return $this->post('/register', ['name' => 'Rita Researcher', 'email' => 'rita@example.org', 'password' => 'a long enough password',
            'password_confirmation' => 'a long enough password', 'reason' => 'Family history.', ...$fields]);
    }

    public function test_closed_until_the_institution_opens_it_in_settings(): void
    {
        $this->get('/register')->assertNotFound();
        $this->get('/sign-in')->assertDontSee('Ask for one');
        $this->actingAs($this->admin)->post('/settings', ['name' => $this->institution->name, 'registration_open' => '1'])->assertRedirect();
        $this->assertTrue($this->institution->fresh()->registration_open);
        $this->app['auth']->forgetGuards();
        $this->get('/sign-in')->assertSee(route('register'), false);
        $this->get('/register')->assertOk()->assertSee('Ask for an account');
    }

    public function test_a_visitor_asks_an_administrator_approves_and_the_account_signs_in_with_its_own_password(): void
    {
        $this->institution->update(['registration_open' => true]);
        $this->ask()->assertRedirect(route('sign-in'))->assertSessionHas('status');
        $request = RegistrationRequest::sole();
        $this->assertNotSame('a long enough password', $request->getRawOriginal('password'), 'kept hashed');
        $this->ask()->assertSessionHasErrors('email');
        $this->ask(['email' => 'bot@example.org', 'website' => 'http://spam.example'])->assertRedirect(route('sign-in'));
        $this->assertSame(1, RegistrationRequest::count(), 'a form that fills the hidden field is ignored');
        $this->ask(['email' => 'short@example.org', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->post('/sign-in', ['email' => 'rita@example.org', 'password' => 'a long enough password']);
        $this->assertGuest();

        $editor = Role::where('institution_id', $this->institution->id)->where('is_system', false)->get()->first(fn ($r) => $r->template?->notation === 'editor' || str_contains(strtolower($r->name()), 'editor'));
        $this->actingAs($this->admin)->get('/registrations')->assertOk()->assertSee('Rita Researcher')->assertSee('Family history.');
        $this->actingAs($this->admin)->post("/registrations/{$request->id}/approve", ['roles' => [$editor->id]])->assertRedirect();
        $rita = User::where('email', 'rita@example.org')->sole();
        $this->assertTrue($rita->roles()->whereKey($editor->id)->exists());
        $this->assertSame($rita->id, $request->fresh()->account_id);
        $this->assertTrue(DB::table('audit_log')->where('subject_id', $request->id)->exists(), 'the decision is audited');

        $this->app['auth']->forgetGuards();
        $this->post('/sign-in', ['email' => 'rita@example.org', 'password' => 'a long enough password'])->assertRedirect();
        $this->assertAuthenticatedAs($rita);
        $this->actingAs($this->admin)->post("/registrations/{$request->id}/approve")->assertSessionHasErrors('registration');
    }

    public function test_an_administrator_rejects_and_only_administrators_see_the_requests(): void
    {
        $this->institution->update(['registration_open' => true]);
        $this->ask();
        $request = RegistrationRequest::sole();
        $archivist = app(AccountService::class)->create($this->institution, 'An Archivist', 'archivist@example.org', 'correct horse battery staple');
        $this->actingAs($archivist)->get('/registrations')->assertForbidden();
        $this->actingAs($archivist)->post("/registrations/{$request->id}/reject")->assertForbidden();
        $this->actingAs($this->admin)->post("/registrations/{$request->id}/reject")->assertRedirect(route('registrations.index'));
        $this->assertNotNull($request->fresh()->rejected_at);
        $this->assertNull(User::where('email', 'rita@example.org')->first());
        $this->actingAs($this->admin)->get('/registrations')->assertSee('Rejected');
        // Asking again is a new request.
        $this->app['auth']->forgetGuards();
        $this->ask()->assertRedirect(route('sign-in'));
        $this->assertSame(2, RegistrationRequest::count());
    }
}
