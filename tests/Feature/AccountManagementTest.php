<?php

// OpenRiC Workspace - administrators manage accounts in the browser.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\AccessControl;
use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\ContactPointService;
use App\Models\ContactPoint;
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
        app(RoleService::class)->seedDefaults($this->institution);
        $this->admin = $this->account('The Administrator', 'admin@example.org', 'administrator');
    }

    private function account(string $name, string $email, ?string $role = null, ?Institution $institution = null): User
    {
        $user = $this->accounts->create($institution ?? $this->institution, $name, $email, self::PASSWORD);
        if ($role) {
            $this->accounts->grantTemplateRole($user, $role);
        }

        return $user;
    }

    private function actions(): array
    {
        return DB::table('audit_log as a')->join('concepts as c', 'c.id', '=', 'a.action_id')->orderBy('a.seq')->pluck('c.notation')->all();
    }

    public function test_only_an_account_with_manage_accounts_reaches_the_screens(): void
    {
        $archivist = $this->account('An Archivist', 'archivist@example.org', 'editor');

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
            'roles' => [app(RoleService::class)->byTemplate($this->institution, 'translator')->id],
        ]);

        $account = User::where('email', 'reviewer@example.org')->firstOrFail();
        $response->assertRedirect(route('accounts.show', $account))->assertSessionHas('password_link');
        $this->assertSame(['Translator'], $account->roles->map(fn ($r) => $r->name())->all());
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
        $account = $this->account('New Archivist', 'new@example.org', 'editor');
        $link = $this->accounts->issuePasswordLink($account, $this->admin);
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        $token = basename((string) parse_url($link, PHP_URL_PATH));

        $this->get($link)->assertOk()->assertSee('Set your password');
        $set = ['token' => $token, 'email' => $query['email'], 'password' => 'my own long passphrase', 'password_confirmation' => 'my own long passphrase'];
        $this->post('/set-password', $set)->assertRedirect(route('sign-in'));
        $this->post('/sign-in', ['email' => 'new@example.org', 'password' => 'my own long passphrase'])->assertRedirect(route('discover.home'));
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
        $archivist = $this->account('An Archivist', 'archivist@example.org', 'editor');
        $this->post('/sign-in', ['email' => 'archivist@example.org', 'password' => self::PASSWORD]);
        $this->assertAuthenticated();

        $this->accounts->disable($archivist, $this->admin);
        // A real next request loads the account afresh; in a test, forget the one already loaded.
        $this->app['auth']->forgetGuards();
        $this->get('/')->assertRedirect(route('sign-in'));
        $this->assertGuest();
    }

    public function test_an_administrator_edits_an_account_its_contact_details_and_translation_languages(): void
    {
        $archivist = $this->account('An Archivist', 'archivist@example.org', 'editor');
        $this->actingAs($this->admin)->get("/accounts/{$archivist->id}")->assertSee(route('accounts.edit', $archivist), false);
        $this->actingAs($this->admin)->get("/accounts/{$archivist->id}/edit")->assertOk()->assertSee('Contact details')->assertSee('besides those of its roles');

        $this->actingAs($this->admin)->post("/accounts/{$archivist->id}", ['name' => 'Anna Archivist', 'email' => ' Anna@Example.org ', 'translation_languages' => 'af, fr af'])
            ->assertRedirect(route('accounts.edit', $archivist))->assertSessionHas('status');
        $archivist->refresh();
        $this->assertSame(['Anna Archivist', 'anna@example.org'], [$archivist->name, $archivist->email]);
        $this->assertSame(['af', 'fr'], $this->accounts->translationLanguages($archivist));
        $this->assertContains('fr', app(AccessControl::class)->translationLanguages($archivist), 'its own languages count with its roles\'');

        // Heratio's rules: a name, and an email no other account signs in with; a language must be a BCP 47 tag.
        $this->actingAs($this->admin)->post("/accounts/{$archivist->id}", ['name' => '', 'email' => 'admin@example.org'])->assertSessionHasErrors(['name', 'email']);
        $this->actingAs($this->admin)->post("/accounts/{$archivist->id}", ['name' => 'Anna', 'email' => 'anna@example.org', 'translation_languages' => 'not a tag!'])->assertSessionHasErrors('account');

        $this->actingAs($this->admin)->post("/accounts/{$archivist->id}/contact", ['city' => 'Pretoria', 'country_code' => 'za', 'telephone' => '012 345 6789'])->assertRedirect();
        $contact = ContactPoint::where(['subject_table' => 'users', 'subject_id' => $archivist->id])->sole();
        $this->assertSame(['Pretoria', 'ZA', true], [$contact->city, $contact->country_code, $contact->is_primary]);
        $this->assertContains('update', $this->actions(), 'the changes are audited');

        // Only within the institution.
        $other = $this->account('Elsewhere', 'elsewhere@example.org', null, Institution::create(['name' => 'Elsewhere', 'base_iri' => 'https://elsewhere.example.org']));
        $this->actingAs($this->admin)->get("/accounts/{$other->id}/edit")->assertNotFound();
    }

    public function test_an_account_edits_its_own_profile_but_not_its_permissions(): void
    {
        $archivist = $this->account('An Archivist', 'archivist@example.org', 'editor');
        $this->actingAs($archivist)->get('/')->assertSee(route('account.profile'), false);
        $this->actingAs($archivist)->get('/account')->assertOk()->assertSee('An Archivist')->assertSee('Edit profile');
        $this->actingAs($archivist)->get('/account/edit')->assertOk()->assertDontSee('besides those of its roles');

        $this->actingAs($archivist)->post('/account', ['name' => 'Anna', 'email' => 'anna@example.org', 'translation_languages' => 'fr'])->assertRedirect(route('account.edit'));
        $this->assertSame(['Anna', 'anna@example.org'], [$archivist->fresh()->name, $archivist->fresh()->email]);
        $this->assertSame([], $this->accounts->translationLanguages($archivist), 'an owner cannot give themselves languages');
        $this->actingAs($archivist->fresh())->post('/account/contact', ['city' => 'Potchefstroom'])->assertRedirect(route('account.edit'));
        $this->actingAs($archivist->fresh())->get('/account')->assertSee('Potchefstroom');

        // The administrator screens stay closed to the owner.
        $this->actingAs($archivist->fresh())->get("/accounts/{$archivist->id}/edit")->assertForbidden();
        $this->actingAs($archivist->fresh())->post("/accounts/{$archivist->id}", ['name' => 'X', 'email' => 'x@example.org'])->assertForbidden();
    }

    public function test_only_an_account_that_never_acted_can_be_deleted(): void
    {
        $unused = $this->account('Created By Mistake', 'mistake@example.org', 'editor');
        app(ContactPointService::class)->save($unused, ['city' => 'Pretoria']);
        $this->actingAs($this->admin)->get("/accounts/{$unused->id}")->assertSee(route('accounts.delete', $unused), false);
        $this->actingAs($this->admin)->get("/accounts/{$unused->id}/delete")->assertOk()->assertSee('cannot be undone');
        $this->actingAs($this->admin)->post("/accounts/{$unused->id}/delete")->assertRedirect(route('accounts.index'));
        $this->assertNull(User::find($unused->id));
        $this->assertSame(0, DB::table('contact_points')->where('subject_id', $unused->id)->count());
        $this->assertContains('delete', $this->actions(), 'the deletion is audited');

        // One that has acted is named in the audit log, and is kept.
        $worker = $this->account('Has Worked', 'worker@example.org', 'editor');
        $this->actingAs($worker)->post('/account', ['name' => 'Has Worked Here', 'email' => 'worker@example.org', 'translation_languages' => '']);
        $this->assertTrue(DB::table('audit_log')->where('actor_id', $worker->id)->exists());
        $this->actingAs($this->admin)->post("/accounts/{$worker->id}/delete")->assertRedirect(route('accounts.show', $worker))->assertSessionHasErrors('account');
        $this->assertNotNull(User::find($worker->id));
        $this->assertTrue(DB::table('role_members')->where('user_id', $worker->id)->exists(), 'nothing of it was removed');

        // Not oneself, and not the last administrator.
        $this->actingAs($this->admin)->post("/accounts/{$this->admin->id}/delete")->assertSessionHasErrors('account');
        $this->assertNotNull($this->admin->fresh());
    }

    public function test_what_an_account_may_do_is_shown_kind_by_kind_with_the_rules_behind_it(): void
    {
        $archivist = $this->account('An Archivist', 'archivist@example.org', 'editor');
        $page = $this->actingAs($this->admin)->get("/accounts/{$archivist->id}/access")->assertOk()
            ->assertSee('What An Archivist may do')->assertSee('<h2>Description</h2>', false)->assertSee('by Editor');
        $this->assertMatchesRegularExpression('~<td>manage accounts</td><td>No</td>~', $page->getContent(), 'an editor manages no accounts');
        $this->assertMatchesRegularExpression('~<td>update</td><td>Yes</td>~', $page->getContent());
        $this->actingAs($this->admin)->get("/accounts/{$archivist->id}")->assertSee(route('accounts.access', $archivist), false);

        // The owner sees their own, and no one else's.
        $this->actingAs($archivist)->get('/account/access')->assertOk()->assertSee('What you may do');
        $this->actingAs($archivist)->get("/accounts/{$this->admin->id}/access")->assertForbidden();
    }
}
