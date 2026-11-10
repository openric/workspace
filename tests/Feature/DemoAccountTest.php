<?php

// OpenRiC Workspace - a demonstration installation's public account (after Heratio's demo): shown on the sign-in page
// only where configured, an administrator, kept from every change that would take it from the next visitor, and put
// back by workspace:demo:account after the nightly reset.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountException;
use App\Accounts\AccountService;
use App\Accounts\TwoFactor;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoAccountTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $admin = app(AccountService::class)->create($this->institution, 'Administrator', 'admin@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($admin, 'administrator');
    }

    private function demo(): void
    {
        config(['workspace.demo_account' => ['email' => 'louise@theahg.co.za', 'password' => 'Password@123']]);
    }

    public function test_nothing_of_it_exists_unless_configured(): void
    {
        $this->get('/sign-in')->assertOk()->assertDontSee('Demonstration</strong>', false);
        $this->artisan('workspace:demo:account')->expectsOutputToContain('nothing to do')->assertSuccessful();
        $this->assertFalse(User::where('email', 'louise@theahg.co.za')->exists());
    }

    public function test_the_account_is_made_shown_and_signs_in_as_an_administrator(): void
    {
        $this->demo();
        $this->artisan('workspace:demo:account')->assertSuccessful();
        $louise = User::where('email', 'louise@theahg.co.za')->sole();
        $this->assertTrue($louise->isDemo());
        $this->assertTrue($louise->may('manage-institution', 'institution'));

        $this->get('/sign-in')->assertOk()->assertSee('<code>louise@theahg.co.za</code> / <code>Password@123</code>', false);
        $this->post('/sign-in', ['email' => 'louise@theahg.co.za', 'password' => 'Password@123'])->assertRedirect();
        $this->assertAuthenticatedAs($louise);
    }

    public function test_no_visitor_can_take_it_from_the_next_and_the_reset_puts_it_back(): void
    {
        $this->demo();
        $this->artisan('workspace:demo:account')->assertSuccessful();
        $louise = User::where('email', 'louise@theahg.co.za')->sole();
        $accounts = app(AccountService::class);
        foreach ([
            fn () => $accounts->update($louise, 'Someone else', 'other@example.org', $louise),
            fn () => $accounts->changePassword($louise, 'another password entirely'),
            fn () => $accounts->setRoles($louise, [], $louise),
            fn () => $accounts->revokeTemplateRole($louise, 'administrator', $louise),
            fn () => $accounts->disable($louise, $louise),
            fn () => $accounts->issuePasswordLink($louise, $louise),
            fn () => $accounts->delete($louise, User::where('email', 'admin@example.org')->sole()),
            fn () => app(TwoFactor::class)->begin($louise),
        ] as $i => $attempt) {
            try {
                $attempt();
                $this->fail("attempt $i was not refused");
            } catch (AccountException $e) {
                $this->assertStringContainsString('demonstration account cannot be changed', $e->getMessage());
            }
        }
        $this->assertFalse(app(TwoFactor::class)->required($louise->fresh()), 'never required to set up two-factor sign-in');

        // Through the page, the refusal is said on the form.
        $this->actingAs($louise)->from('/account/password')->post('/account/password', ['current_password' => 'Password@123',
            'password' => 'another password entirely', 'password_confirmation' => 'another password entirely'])
            ->assertRedirect('/account/password')->assertSessionHasErrors('account');
        $this->assertTrue(Hash::check('Password@123', $louise->fresh()->password));

        // Whatever happened to it, the reset's repair puts it back.
        $louise->forceFill(['password' => 'changed behind the guard', 'disabled_at' => now()])->save();
        $this->artisan('workspace:demo:account')->assertSuccessful();
        $this->assertTrue(Hash::check('Password@123', $louise->fresh()->password));
        $this->assertNull($louise->fresh()->disabled_at);
    }
}
