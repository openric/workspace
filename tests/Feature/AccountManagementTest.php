<?php

// OpenRiC Workspace - administrators manage accounts in the browser.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Accounts\AccountService;
use App\Models\Institution;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountManagementTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct horse battery staple';

    private AccountService $accounts;

    private Institution $institution;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        $this->accounts = app(AccountService::class);
        $this->institution = Institution::create(['name' => 'Riverside Parish Council Archives', 'base_iri' => 'https://archives.example.org']);
        $this->accounts->seedDefaultGrants($this->institution);
        $this->admin = $this->account('The Administrator', 'admin@example.org', 'administrator');
    }

    private function account(string $name, string $email, ?string $role = null, ?Institution $institution = null): User
    {
        $user = $this->accounts->create($institution ?? $this->institution, $name, $email, self::PASSWORD);
        if ($role) {
            $this->accounts->grantRole($user, Vocab::role($role));
        }

        return $user;
    }

    private function actions(): array
    {
        return DB::table('audit_log as a')->join('concepts as c', 'c.id', '=', 'a.action_id')->orderBy('a.seq')->pluck('c.notation')->all();
    }

    public function test_only_an_account_with_manage_accounts_reaches_the_screens(): void
    {
        $archivist = $this->account('An Archivist', 'archivist@example.org', 'archivist');

        $this->actingAs($archivist)->get('/accounts')->assertForbidden();
        $this->actingAs($this->admin)->get('/accounts')->assertOk()->assertSee('An Archivist');
    }

    public function test_an_administrator_sees_only_their_own_institution(): void
    {
        $elsewhere = Institution::create(['name' => 'Elsewhere', 'base_iri' => 'https://elsewhere.example.org']);
        $stranger = $this->account('A Stranger', 'stranger@example.org', null, $elsewhere);

        $this->actingAs($this->admin)->get('/accounts')->assertDontSee('A Stranger');
        $this->actingAs($this->admin)->get("/accounts/{$stranger->id}")->assertNotFound();
        $this->actingAs($this->admin)->post("/accounts/{$stranger->id}/disable")->assertNotFound();
        $this->actingAs($this->admin)->get('/accounts/not-a-uuid')->assertNotFound();
    }

    public function test_a_new_account_gets_its_roles_and_a_link_to_set_its_password(): void
    {
        $response = $this->actingAs($this->admin)->post('/accounts', [
            'name' => 'New Reviewer',
            'email' => 'Reviewer@Example.org',
            'roles' => [Vocab::role('reviewer')],
        ]);

        $account = User::where('email', 'reviewer@example.org')->firstOrFail();
        $response->assertRedirect(route('accounts.show', $account))->assertSessionHas('password_link');
        $this->assertSame([Vocab::role('reviewer')], $account->roles->pluck('iri')->all());
        $this->assertSame(['create', 'grant-role', 'issue-password-link'], array_slice($this->actions(), -3));
    }

    public function test_a_role_outside_the_vocabulary_is_refused(): void
    {
        $this->actingAs($this->admin)->post('/accounts', [
            'name' => 'X', 'email' => 'x@example.org', 'roles' => [Vocab::permission('publish')],
        ])->assertSessionHasErrors('roles.0');
        $this->assertFalse(User::where('email', 'x@example.org')->exists());
    }

    public function test_the_link_sets_the_password_once(): void
    {
        $account = $this->account('New Archivist', 'new@example.org', 'archivist');
        $link = $this->accounts->issuePasswordLink($account, $this->admin);
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        $token = basename((string) parse_url($link, PHP_URL_PATH));

        $this->get($link)->assertOk()->assertSee('Set your password');
        $set = ['token' => $token, 'email' => $query['email'], 'password' => 'my own long passphrase', 'password_confirmation' => 'my own long passphrase'];
        $this->post('/set-password', $set)->assertRedirect(route('sign-in'));
        $this->post('/sign-in', ['email' => 'new@example.org', 'password' => 'my own long passphrase'])->assertRedirect(route('home'));
        $this->post('/sign-out');

        $this->post('/set-password', [...$set, 'password' => 'another long passphrase', 'password_confirmation' => 'another long passphrase'])
            ->assertSessionHasErrors('password');
        $this->assertContains('set-password', $this->actions());
    }

    public function test_a_short_password_is_refused(): void
    {
        $this->actingAs($this->admin)->post('/account/password', [
            'current_password' => self::PASSWORD, 'password' => 'short', 'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');
    }

    public function test_changing_your_password_needs_the_current_one(): void
    {
        $this->actingAs($this->admin)->post('/account/password', [
            'current_password' => 'wrong', 'password' => 'a brand new passphrase', 'password_confirmation' => 'a brand new passphrase',
        ])->assertSessionHasErrors('current_password');

        $this->actingAs($this->admin)->post('/account/password', [
            'current_password' => self::PASSWORD, 'password' => 'a brand new passphrase', 'password_confirmation' => 'a brand new passphrase',
        ])->assertRedirect(route('home'));
        $this->assertContains('set-password', $this->actions());
    }

    public function test_the_last_administrator_cannot_be_disabled_or_lose_the_role(): void
    {
        $this->actingAs($this->admin)->post("/accounts/{$this->admin->id}/disable")->assertSessionHasErrors('account');
        $this->actingAs($this->admin)->post("/accounts/{$this->admin->id}/roles", ['roles' => []])->assertSessionHasErrors('account');
        $this->assertFalse($this->admin->fresh()->isDisabled());
        $this->assertTrue($this->admin->fresh()->hasPermission(Vocab::permission('manage-accounts')));
    }

    public function test_with_a_second_administrator_the_first_can_be_disabled(): void
    {
        $second = $this->account('Second Administrator', 'second@example.org', 'administrator');

        $this->actingAs($second)->post("/accounts/{$this->admin->id}/disable")->assertSessionHas('status', 'Account disabled.');
        $this->assertTrue($this->admin->fresh()->isDisabled());

        $this->actingAs($second)->post("/accounts/{$this->admin->id}/enable")->assertSessionHas('status', 'Account enabled.');
        $this->assertFalse($this->admin->fresh()->isDisabled());
    }

    public function test_a_disabled_account_is_signed_out_on_its_next_request(): void
    {
        $archivist = $this->account('An Archivist', 'archivist@example.org', 'archivist');
        $this->post('/sign-in', ['email' => 'archivist@example.org', 'password' => self::PASSWORD]);
        $this->assertAuthenticated();

        $this->accounts->disable($archivist, $this->admin);
        // A real next request loads the account afresh; in a test, forget the one already loaded.
        $this->app['auth']->forgetGuards();
        $this->get('/')->assertRedirect(route('sign-in'));
        $this->assertGuest();
    }
}
