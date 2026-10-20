<?php

// OpenRiC Workspace - roles and the permission matrix (ADR 0005).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\AccessControl;
use App\Access\PermissionMatrix;
use App\Access\RoleService;
use App\Accounts\AccountException;
use App\Accounts\AccountService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Institution;
use App\Models\Permission;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\Role;
use App\Models\User;
use App\Vocabulary\SkosImporter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RolesAndPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private Institution $institution;

    private RoleService $roles;

    private PermissionMatrix $matrix;

    private AccountService $accounts;

    private User $admin;

    private Repository $repoA;

    private Repository $repoB;

    private RecordResource $fonds;

    private RecordResource $file;

    private RecordResource $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/date-type.ttl')));
        $this->roles = app(RoleService::class);
        $this->matrix = app(PermissionMatrix::class);
        $this->accounts = app(AccountService::class);
        $this->institution = Institution::create(['name' => 'Riverside Parish Council Archives', 'base_iri' => 'https://archives.example.org']);
        $this->roles->seedDefaults($this->institution);
        $this->admin = $this->account('admin@example.org', 'administrator');
        $repositories = app(RepositoryService::class);
        $this->repoA = $repositories->create($this->institution, 'Repository A', 'en');
        $this->repoB = $repositories->create($this->institution, 'Repository B', 'en');
        $records = app(RecordService::class);
        $this->fonds = $records->create($this->institution, self::LEVEL.'fonds', 'Fonds in A', 'en', null, $this->repoA);
        $series = $records->create($this->institution, self::LEVEL.'series', 'Series in A', 'en', $this->fonds);
        $this->file = $records->create($this->institution, self::LEVEL.'file', 'File in A', 'en', $series);
        $this->other = $records->create($this->institution, self::LEVEL.'fonds', 'Fonds in B', 'en', null, $this->repoB);
    }

    private function account(string $email, ?string $template = null): User
    {
        $user = $this->accounts->create($this->institution, ucfirst(strtok($email, '@')), $email, 'correct horse battery staple');
        if ($template) {
            $this->accounts->grantTemplateRole($user, $template);
        }

        return $user;
    }

    private function role(string $name, ?Role $parent = null): Role
    {
        return $this->roles->create($this->institution, ['en' => ['name' => $name]], $parent);
    }

    private function may(?User $user, string $action, string $kind, $subject = null): bool
    {
        app(AccessControl::class)->flush();

        return app(AccessControl::class)->allows($user, $action, $kind, $subject, $this->institution);
    }

    public function test_the_default_roles_and_their_hierarchy(): void
    {
        $names = Role::where('institution_id', $this->institution->id)->get()->mapWithKeys(fn ($r) => [$r->template->notation => $r]);
        $this->assertEqualsCanonicalizing(['public', 'signed-in', 'administrator', 'editor', 'contributor', 'translator'], $names->keys()->all());
        $this->assertNull($names['public']->parent_id);
        $this->assertSame($names['public']->id, $names['signed-in']->parent_id);
        $this->assertSame($names['signed-in']->id, $names['editor']->parent_id);
        $this->assertTrue($names['public']->is_system && $names['signed-in']->is_system && ! $names['editor']->is_system);
        $this->assertTrue($this->may($this->admin, 'manage-roles', 'institution'));
    }

    public function test_the_public_reads_but_does_not_change(): void
    {
        $this->assertTrue($this->may(null, 'read', 'description', $this->fonds));
        $this->assertFalse($this->may(null, 'update', 'description', $this->fonds));
        $this->assertFalse($this->may(null, 'view-draft', 'description', $this->fonds));
        $plain = $this->account('plain@example.org');
        $this->assertTrue($this->may($plain, 'read', 'repository', $this->repoA), 'a signed-in account inherits Public');
        $this->assertFalse($this->may($plain, 'create', 'repository'));
    }

    public function test_a_denial_wins_over_a_grant_at_the_same_level(): void
    {
        $user = $this->account('both@example.org', 'editor');
        $this->assertTrue($this->may($user, 'update', 'description', $this->fonds));
        $blocker = $this->role('No updates');
        $this->matrix->apply($blocker, 'description', ['update|all' => 'deny']);
        $this->roles->addMember($blocker, $user);

        $this->assertFalse($this->may($user, 'update', 'description', $this->fonds));
    }

    public function test_a_specific_rule_comes_before_a_general_one(): void
    {
        $role = $this->role('One fonds only');
        $this->matrix->apply($role, 'description', ['update|all' => 'deny', 'update|record:'.$this->fonds->id => 'grant']);
        $user = $this->account('one@example.org');
        $this->roles->addMember($role, $user);

        $this->assertTrue($this->may($user, 'update', 'description', $this->fonds));
        $this->assertTrue($this->may($user, 'update', 'description', $this->file), 'a rule on a unit covers what is inside it');
        $this->assertFalse($this->may($user, 'update', 'description', $this->other));
    }

    public function test_a_repository_rule_covers_only_that_repository(): void
    {
        $role = $this->role('Repository A editors');
        $this->matrix->apply($role, 'description', ['update|repository:'.$this->repoA->id => 'grant']);
        $user = $this->account('repo@example.org');
        $this->roles->addMember($role, $user);

        $this->assertTrue($this->may($user, 'update', 'description', $this->file));
        $this->assertFalse($this->may($user, 'update', 'description', $this->other));
        $this->assertFalse($this->may($user, 'update', 'description'), 'not in general');
        $this->assertTrue(app(AccessControl::class)->allowsAnywhere($user, 'update', 'description'));
    }

    public function test_an_accounts_own_permission_comes_before_its_roles(): void
    {
        $user = $this->account('own@example.org', 'editor');
        $this->matrix->apply($user, 'description', ['delete|all' => 'deny']);
        $this->assertFalse($this->may($user, 'delete', 'description', $this->fonds));

        $translator = $this->account('t@example.org', 'translator');
        $this->assertFalse($this->may($translator, 'update', 'description', $this->fonds));
        $this->matrix->apply($translator, 'description', ['update|record:'.$this->fonds->id => 'grant']);
        $this->assertTrue($this->may($translator, 'update', 'description', $this->fonds));
    }

    public function test_a_role_inherits_from_its_parent_and_never_from_itself(): void
    {
        $senior = $this->role('Senior editor', $this->roles->byTemplate($this->institution, 'editor'));
        $user = $this->account('senior@example.org');
        $this->roles->addMember($senior, $user);
        $this->assertTrue($this->may($user, 'publish', 'description', $this->fonds));

        $editor = $this->roles->byTemplate($this->institution, 'editor');
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('inherit from itself');
        $editor->update(['parent_id' => $senior->id]);
    }

    public function test_a_translator_writes_only_its_languages_and_only_values(): void
    {
        $translator = $this->account('translator@example.org', 'translator');
        $this->roles->setTranslationLanguages($this->roles->byTemplate($this->institution, 'translator'), ['af', 'zu']);
        $fonds = $this->fonds;

        $this->actingAs($translator)->get("/records/{$fonds->id}/edit?language=af")->assertOk();
        $this->actingAs($translator)->get("/records/{$fonds->id}/edit?language=en")->assertForbidden();
        $this->actingAs($translator)->get("/records/{$fonds->id}/edit?language=fr")->assertForbidden();
        $this->actingAs($translator)->post("/records/{$fonds->id}", ['language' => 'af', 'level' => self::LEVEL.'fonds', 'values' => ['title' => 'Fonds in A (af)']])
            ->assertRedirect(route('records.show', $fonds));
        $this->assertSame('Fonds in A (af)', $fonds->fresh()->described('title', ['af']));
        $this->actingAs($translator)->post("/records/{$fonds->id}", ['language' => 'af', 'level' => self::LEVEL.'series', 'values' => ['title' => 'x']])
            ->assertForbidden();
    }

    public function test_system_and_administrator_roles_cannot_be_removed(): void
    {
        foreach (['public', 'signed-in', 'administrator'] as $template) {
            try {
                $this->roles->remove($this->roles->byTemplate($this->institution, $template));
                $this->fail("$template was removed");
            } catch (AccountException) {
                $this->assertNotNull($this->roles->byTemplate($this->institution, $template));
            }
        }
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('system role cannot be removed');
        DB::table('roles')->where('id', $this->roles->byTemplate($this->institution, 'public')->id)->delete();
    }

    public function test_removing_a_role_removes_its_members_and_permissions_and_audits_them(): void
    {
        $role = $this->role('Temporary');
        $this->matrix->apply($role, 'description', ['update|all' => 'grant']);
        $user = $this->account('temp@example.org');
        $this->roles->addMember($role, $user);

        $this->actingAs($this->admin)->post("/roles/{$role->id}/remove")->assertRedirect(route('roles.index'));
        $this->assertNull(Role::find($role->id));
        $this->assertFalse(Permission::where('role_id', $role->id)->exists());
        $this->assertTrue(DB::table('audit_log')->where('subject_table', 'permissions')->whereNotNull('before')->whereNull('after')->exists());
        $this->assertFalse($this->may($user->fresh(), 'update', 'description', $this->fonds));
    }

    public function test_the_last_administrator_stays(): void
    {
        $admin = $this->roles->byTemplate($this->institution, 'administrator');
        $this->actingAs($this->admin)->post("/roles/{$admin->id}/members/{$this->admin->id}/remove")->assertSessionHasErrors('members');
        $this->assertTrue($this->may($this->admin->fresh(), 'manage-roles', 'institution'));
    }

    public function test_the_matrix_screen_grants_denies_and_inherits(): void
    {
        $role = $this->role('Screen role');
        $this->actingAs($this->admin)->get('/roles')->assertOk()->assertSee('Screen role');
        $this->actingAs($this->admin)->get("/roles/{$role->id}")->assertOk()->assertSee('Archival description');
        $this->actingAs($this->admin)->get("/roles/{$role->id}/permissions/description?repository={$this->repoA->id}")->assertOk()
            ->assertSee('Everything')->assertSee('In the repository Repository A')->assertSee('View draft');

        $url = "/roles/{$role->id}/permissions/description";
        $this->actingAs($this->admin)->post($url, ['cell' => ['update|all' => 'grant', 'delete|all' => 'deny', 'publish|repository:'.$this->repoA->id => 'grant']])->assertRedirect($url);
        $this->assertSame(3, Permission::where('role_id', $role->id)->count());
        $this->actingAs($this->admin)->post($url, ['cell' => ['update|all' => 'deny', 'delete|all' => 'inherit']]);
        $this->assertFalse(Permission::where('role_id', $role->id)->whereHas('action', fn ($q) => $q->where('notation', 'update'))->first()->is_grant);
        $this->assertSame(2, Permission::where('role_id', $role->id)->count());

        $this->actingAs($this->admin)->post($url, ['cell' => ['manage-roles|all' => 'grant']])->assertSessionHasErrors('cell');
        $elsewhere = Institution::create(['name' => 'Elsewhere', 'base_iri' => 'https://elsewhere.example.org']);
        $theirs = app(RepositoryService::class)->create($elsewhere, 'Theirs', 'en');
        $this->actingAs($this->admin)->post($url, ['cell' => ['update|repository:'.$theirs->id => 'grant']])->assertSessionHasErrors('cell');
    }

    public function test_an_accounts_own_matrix_screen(): void
    {
        $user = $this->account('person@example.org');
        $this->actingAs($this->admin)->get("/accounts/{$user->id}/permissions/repository")->assertOk();
        $this->actingAs($this->admin)->post("/accounts/{$user->id}/permissions/repository", ['cell' => ['create|all' => 'grant']]);
        $this->assertTrue($this->may($user, 'create', 'repository'));
    }

    public function test_roles_need_manage_roles(): void
    {
        $editor = $this->account('editor@example.org', 'editor');
        $this->actingAs($editor)->get('/roles')->assertForbidden();
        $this->actingAs($editor)->get('/roles/'.$this->roles->byTemplate($this->institution, 'editor')->id)->assertForbidden();
        $this->actingAs($editor)->get('/')->assertDontSee('Roles and permissions');
    }

    public function test_a_role_is_created_renamed_and_reparented_on_screen(): void
    {
        $this->actingAs($this->admin)->post('/roles', ['name' => 'Cataloguer', 'language' => 'en', 'description' => 'Catalogues new accessions.'])->assertRedirect();
        $role = Role::whereHas('labels', fn ($q) => $q->where('name', 'Cataloguer'))->firstOrFail();
        $this->assertSame($this->roles->byTemplate($this->institution, 'signed-in')->id, $role->parent_id, 'new roles inherit from Signed in');

        $editor = $this->roles->byTemplate($this->institution, 'editor');
        $this->actingAs($this->admin)->post("/roles/{$role->id}", ['name' => 'Katalogiseerder', 'language' => 'af', 'parent' => $editor->id]);
        $role->refresh();
        $this->assertSame('Katalogiseerder', $role->name(['af']));
        $this->assertSame('Cataloguer', $role->name(['en']));
        $this->assertSame($editor->id, $role->parent_id);
    }

    public function test_drafts_are_hidden_without_view_draft(): void
    {
        $this->assertFalse($this->may($this->account('reader@example.org'), 'view-draft', 'description', $this->fonds));
        $reader = User::where('email', 'reader@example.org')->first();
        $this->actingAs($reader)->get("/records/{$this->fonds->id}")->assertOk()->assertDontSee('Fonds in A</p>', false)->assertDontSee('Edit description');
        $editor = $this->account('ed@example.org', 'editor');
        $this->actingAs($editor)->get("/records/{$this->fonds->id}")->assertOk()->assertSee('Edit description');
    }

    public function test_changing_or_removing_a_role_name_in_one_language_keeps_the_others(): void
    {
        $roles = app(RoleService::class);
        $role = $roles->create($this->institution, ['en' => ['name' => 'Cataloguer'], 'af' => ['name' => 'Katalogiseerder']]);
        $roles->update($role, ['en' => ['name' => 'Senior cataloguer']], null);
        $this->assertSame(['af' => 'Katalogiseerder', 'en' => 'Senior cataloguer'], $role->labels()->orderBy('language')->pluck('name', 'language')->all());
        $roles->update($role, ['af' => ['name' => '']], null);
        $this->assertSame(['en' => 'Senior cataloguer'], $role->labels()->pluck('name', 'language')->all());
    }
}
