<?php

// OpenRiC Workspace - API keys (ADR 0007): issuing, scopes no wider than the account's, reads as the public,
// revoking, expiry, the hourly and daily limits, and the key pages.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\AccessControl;
use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Accounts\ApiKeyService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\ApiKey;
use App\Models\Institution;
use App\Models\User;
use App\Support\Vocab;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApiKeyTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://archives.example.org/api/ric/v1';

    private Institution $institution;

    private User $admin;

    private User $contributor;

    private User $translator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $accounts = app(AccountService::class);
        foreach (['admin' => 'administrator', 'contributor' => 'contributor', 'translator' => 'translator'] as $property => $role) {
            $this->$property = $accounts->create($this->institution, ucfirst($property), "$property@example.org", 'correct horse battery staple');
            $accounts->grantTemplateRole($this->$property, $role);
        }
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $records = app(RecordService::class);
        $published = $records->create($this->institution, 'https://workspace.openric.org/vocab/level-of-description/fonds', 'Parish records', 'en', null, $holder, 'P');
        $records->create($this->institution, 'https://workspace.openric.org/vocab/level-of-description/fonds', 'Unpublished papers', 'en', null, $holder, 'U');
        app(PublicationService::class)->apply($holder, 'publish', null, false, $this->admin);
        app(PublicationService::class)->apply($published, 'publish', null, false, $this->admin);
    }

    /** @return array{0: ApiKey, 1: string} */
    private function issue(User $owner, array $scopes = ['read'], ?int $daily = null, ?CarbonImmutable $ends = null): array
    {
        return app(ApiKeyService::class)->issue($owner, 'Test key', $scopes, $ends, $daily, $owner);
    }

    public function test_a_key_carries_only_the_scopes_its_account_holds(): void
    {
        [$key] = $this->issue($this->contributor, ['read', 'write']);
        $this->assertSame(['read', 'write'], $key->scopes()->pluck('notation')->sort()->values()->all());

        // A translator may create or change neither descriptions nor authority records: read only.
        [$key] = $this->issue($this->translator, ['read', 'write']);
        $this->assertSame(['read'], $key->scopes()->pluck('notation')->all());
        [$key] = $this->issue($this->translator, ['write']);
        $this->assertSame(['read'], $key->scopes()->pluck('notation')->all());
    }

    public function test_a_key_loses_a_scope_when_its_account_does(): void
    {
        [$key] = $this->issue($this->contributor, ['read', 'write']);
        $keys = app(ApiKeyService::class);
        $this->assertSame(['read', 'write'], $keys->effectiveScopes($key->fresh()));

        app(AccountService::class)->revokeTemplateRole($this->contributor, 'contributor');
        app(AccessControl::class)->flush();
        $this->assertSame(['read'], $keys->effectiveScopes($key->fresh()));
    }

    public function test_only_a_fingerprint_is_kept_and_the_audit_log_names_the_scopes(): void
    {
        [$key, $secret] = $this->issue($this->contributor, ['read', 'write']);
        $this->assertSame(48, strlen($secret));
        $this->assertSame(hash('sha256', $secret), DB::table('api_keys')->where('id', $key->id)->value('key_hash'));
        $this->assertSame(substr($secret, 0, 8), $key->prefix);
        $this->assertArrayNotHasKey('key_hash', $key->toArray());

        $rows = DB::table('audit_log')->where('subject_id', $key->id)->get();
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertStringNotContainsString(hash('sha256', $secret), (string) $row->after);
            $this->assertStringNotContainsString($secret, (string) $row->after);
        }
        $this->assertTrue($rows->contains(fn ($row) => str_contains((string) $row->after, Vocab::apiScope('write'))));
    }

    public function test_reads_with_a_key_see_what_the_public_sees(): void
    {
        [, $secret] = $this->issue($this->admin, ['read', 'write']);
        $anonymous = $this->getJson(self::API.'/records')->assertOk()->json('openric:items');
        $keyed = $this->getJson(self::API.'/records', ['X-API-Key' => $secret])->assertOk()->json('openric:items');
        $this->assertSame($anonymous, $keyed);
        $this->assertSame(['Parish records'], array_column($keyed, 'rico:title'));
        $this->getJson(self::API.'/records', ['Authorization' => "Bearer $secret"])->assertOk();
        $this->assertNotNull(ApiKey::first()->last_used_at);
    }

    public function test_a_key_that_is_unknown_revoked_expired_or_of_a_disabled_account_is_refused(): void
    {
        $this->getJson(self::API.'/health', ['X-API-Key' => 'not-a-key'])->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json')->assertJsonPath('type', 'https://openric.org/errors/authentication-required');

        [$revoked, $secret] = $this->issue($this->contributor);
        app(ApiKeyService::class)->revoke($revoked, $this->contributor);
        $this->getJson(self::API.'/health', ['X-API-Key' => $secret])->assertStatus(401);
        $this->assertNotNull($revoked->fresh()->revoked_at);

        [$expired, $secret] = $this->issue($this->contributor, ['read'], null, CarbonImmutable::now()->addDay());
        $this->travel(2)->days();
        $this->getJson(self::API.'/health', ['X-API-Key' => $secret])->assertStatus(401);
        $this->travelBack();

        [, $secret] = $this->issue($this->translator);
        $this->getJson(self::API.'/health', ['X-API-Key' => $secret])->assertOk();
        app(AccountService::class)->disable($this->translator, $this->admin);
        $this->getJson(self::API.'/health', ['X-API-Key' => $secret])->assertStatus(401);
    }

    public function test_a_key_works_only_for_its_own_institution(): void
    {
        $other = Institution::create(['name' => 'Hill Archives', 'base_iri' => 'https://hill.example.org']);
        app(RoleService::class)->seedDefaults($other);
        [, $secret] = $this->issue($this->contributor);
        $this->getJson('https://hill.example.org/api/ric/v1/health', ['X-API-Key' => $secret])->assertStatus(403);
        $this->getJson(self::API.'/health', ['X-API-Key' => $secret])->assertOk();
    }

    public function test_the_hourly_and_daily_limits_answer_429_with_retry_after(): void
    {
        [$key, $secret] = $this->issue($this->contributor, ['read'], 2);
        $this->getJson(self::API.'/health', ['X-API-Key' => $secret])->assertOk();
        $this->getJson(self::API.'/health', ['X-API-Key' => $secret])->assertOk();
        $refused = $this->getJson(self::API.'/health', ['X-API-Key' => $secret])->assertStatus(429);
        $this->assertGreaterThan(0, (int) $refused->headers->get('Retry-After'));
        $this->assertStringContainsString('daily limit of 2', $refused->json('detail'));

        [$hourly, $secret] = $this->issue($this->contributor);
        $hourly->update(['hourly_limit' => 1]);
        $this->getJson(self::API.'/health', ['X-API-Key' => $secret])->assertOk();
        $refused = $this->getJson(self::API.'/health', ['X-API-Key' => $secret])->assertStatus(429);
        $this->assertLessThanOrEqual(3600, (int) $refused->headers->get('Retry-After'));
        // Without a key, reads stay open.
        $this->getJson(self::API.'/health')->assertOk();
    }

    public function test_an_account_manages_its_own_keys_and_no_one_elses(): void
    {
        $this->actingAs($this->contributor)->post('/account/api-keys', ['name' => 'MCP', 'scopes' => ['read', 'write'], 'daily_limit' => 500])
            ->assertRedirect('/account/api-keys')->assertSessionHas('new_key');
        $key = ApiKey::where('user_id', $this->contributor->id)->firstOrFail();
        $this->assertSame(500, $key->daily_limit);
        $this->actingAs($this->contributor)->get('/account/api-keys')->assertOk()->assertSee('MCP')->assertSee($key->prefix);

        $this->actingAs($this->translator)->post('/account/api-keys', ['name' => 'Too much', 'scopes' => ['write']])->assertSessionHasErrors('scopes.0');
        $this->actingAs($this->translator)->post("/account/api-keys/{$key->id}/revoke")->assertNotFound();
        $this->assertNull($key->fresh()->revoked_at);

        $this->actingAs($this->contributor)->post("/account/api-keys/{$key->id}/revoke")->assertRedirect('/account/api-keys');
        $this->assertNotNull($key->fresh()->revoked_at);
        $this->assertSame($this->contributor->id, $key->fresh()->revoked_by_id);
    }

    public function test_an_administrator_issues_and_revokes_keys_for_another_account(): void
    {
        $this->actingAs($this->contributor)->post("/accounts/{$this->translator->id}/api-keys", ['name' => 'Not mine'])->assertForbidden();

        $this->actingAs($this->admin)->post("/accounts/{$this->translator->id}/api-keys", ['name' => 'Catalogue reader'])
            ->assertRedirect("/accounts/{$this->translator->id}#api-keys")->assertSessionHas('new_key');
        $key = ApiKey::where('user_id', $this->translator->id)->firstOrFail();
        $this->assertSame($this->admin->id, $key->created_by_id);
        $this->actingAs($this->admin)->get("/accounts/{$this->translator->id}")->assertOk()->assertSee('Catalogue reader');

        $this->actingAs($this->admin)->post("/accounts/{$this->translator->id}/api-keys/{$key->id}/revoke")->assertRedirect();
        $this->assertNotNull($key->fresh()->revoked_at);
    }
}
