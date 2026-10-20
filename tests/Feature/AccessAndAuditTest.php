<?php

// OpenRiC Workspace - installation, sign-in, default deny, roles from
// vocabularies, and the append-only audit log.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\AccessControl;
use App\Access\PermissionMatrix;
use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccessAndAuditTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct horse battery staple';

    private Institution $institution;

    private AccountService $accounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        $this->accounts = app(AccountService::class);
        $this->institution = Institution::create(['name' => 'Riverside Parish Council Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
    }

    private function account(?string $role = null, string $email = 'archivist@example.org'): User
    {
        $user = $this->accounts->create($this->institution, 'An Archivist', $email, self::PASSWORD);
        if ($role) {
            $this->accounts->grantTemplateRole($user, $role);
        }

        return $user;
    }

    private function auditActions(): array
    {
        return DB::table('audit_log as a')->join('concepts as c', 'c.id', '=', 'a.action_id')
            ->orderBy('a.occurred_at')->pluck('c.notation')->all();
    }

    public function test_every_page_but_sign_in_needs_an_account(): void
    {
        $this->get('/')->assertRedirect(route('sign-in'));
        $this->get('/audit')->assertRedirect(route('sign-in'));
        $this->get('/sign-in')->assertOk()->assertSee('Sign in');
    }

    public function test_signing_in_and_out_is_audited(): void
    {
        $this->account('editor');

        $this->post('/sign-in', ['email' => 'Archivist@Example.org', 'password' => self::PASSWORD])->assertRedirect(route('home'));
        $this->assertAuthenticated();
        $this->get('/')->assertOk()->assertSee('Riverside Parish Council Archives')->assertSee('Archivist');
        $this->post('/sign-out')->assertRedirect(route('sign-in'));
        $this->assertGuest();

        $this->assertSame(['create', 'grant-role', 'sign-in', 'sign-out'], array_slice($this->auditActions(), -4));
    }

    public function test_a_wrong_password_is_refused_and_audited_without_the_password(): void
    {
        $this->account('editor');

        $this->post('/sign-in', ['email' => 'archivist@example.org', 'password' => 'not the password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertContains('sign-in-failed', $this->auditActions());
        $this->assertFalse(DB::table('audit_log')->where('after', 'like', '%not the password%')->exists());
        $this->assertFalse(DB::table('audit_log')->where('after', 'like', '%'.self::PASSWORD.'%')->exists());
    }

    public function test_a_disabled_account_cannot_sign_in_or_do_anything(): void
    {
        $user = $this->account('editor');
        $this->accounts->disable($user);

        $this->post('/sign-in', ['email' => 'archivist@example.org', 'password' => self::PASSWORD])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertFalse($user->fresh()->hasPermission(Vocab::permission('describe')));
    }

    public function test_a_password_change_is_audited_without_the_password(): void
    {
        $user = $this->account('editor');
        $user->update(['password' => 'a new long password 456']);

        $row = DB::table('audit_log')->whereNotNull('after')->orderByDesc('occurred_at')->first();
        $this->assertSame(['password' => '[redacted]'], json_decode($row->after, true));
        $this->assertSame(['password' => '[redacted]'], json_decode($row->before, true));
        $this->assertFalse(DB::table('audit_log')->where('after', 'like', '%a new long password%')->exists());
    }

    public function test_an_account_without_a_role_is_denied(): void
    {
        $this->actingAs($this->account())->get('/audit')->assertForbidden();
    }

    public function test_a_role_only_has_the_permissions_it_is_granted(): void
    {
        $this->actingAs($this->account('editor'))->get('/audit')->assertForbidden();
    }

    public function test_the_audit_log_is_readable_with_the_permission_and_lost_with_the_role(): void
    {
        $admin = $this->account('administrator');
        $this->account('administrator', 'second-admin@example.org');
        $this->actingAs($admin)->get('/audit')->assertOk()->assertSee('Grant role');

        $this->accounts->revokeTemplateRole($admin, 'administrator');
        $this->actingAs($admin->fresh())->get('/audit')->assertForbidden();
        $this->assertContains('revoke-role', $this->auditActions());
    }

    public function test_a_grant_is_data_not_code(): void
    {
        $archivist = $this->account('editor');
        $this->assertFalse($archivist->hasPermission(Vocab::permission('view-audit-log')));

        $editor = app(RoleService::class)->byTemplate($this->institution, 'editor');
        app(PermissionMatrix::class)->apply($editor, 'institution', ['view-audit-log|all' => 'grant']);
        app(AccessControl::class)->flush();

        $this->assertTrue($archivist->hasPermission(Vocab::permission('view-audit-log')));
    }

    public function test_the_database_refuses_a_concept_from_the_wrong_vocabulary(): void
    {
        $editor = app(RoleService::class)->byTemplate($this->institution, 'editor');
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('must be a concept of');
        DB::table('permissions')->insert([
            'id' => (string) Str::uuid7(), 'institution_id' => $this->institution->id, 'role_id' => $editor->id,
            'kind_id' => Concept::where('iri', Vocab::kind('description'))->value('id'),
            'action_id' => Concept::where('iri', Vocab::role('editor'))->value('id'),
            'is_grant' => true,
        ]);
    }

    public function test_the_database_refuses_to_change_an_audit_row(): void
    {
        $this->account('editor');
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');
        DB::table('audit_log')->update(['ip' => '10.0.0.1']);
    }

    public function test_the_database_refuses_to_delete_an_audit_row(): void
    {
        $this->account('editor');
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');
        DB::table('audit_log')->delete();
    }

    public function test_the_database_refuses_to_truncate_the_audit_log(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');
        DB::statement('TRUNCATE audit_log');
    }

    public function test_repeated_failed_sign_ins_are_throttled(): void
    {
        $this->account('editor');
        for ($i = 0; $i < 5; $i++) {
            $this->post('/sign-in', ['email' => 'archivist@example.org', 'password' => 'wrong']);
        }
        $this->post('/sign-in', ['email' => 'archivist@example.org', 'password' => self::PASSWORD])->assertStatus(429);
    }
}
