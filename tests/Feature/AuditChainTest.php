<?php

// OpenRiC Workspace - the audit log is chained, signed and verifiable (ADR 0004).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Accounts\AccountService;
use App\Audit\AuditChain;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class AuditChainTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        $this->institution = Institution::create(['name' => 'Riverside Parish Council Archives', 'base_iri' => 'https://archives.example.org']);
        app(AccountService::class)->create($this->institution, 'An Archivist', 'archivist@example.org', 'correct horse battery staple');
    }

    public function test_every_row_points_to_the_one_before_it(): void
    {
        $rows = DB::table('audit_log')->orderBy('seq')->get(['seq', 'prev_hash', 'entry_hash', 'signature', 'kid']);

        $this->assertSame([0, 1], $rows->pluck('seq')->map(fn ($s) => (int) $s)->all());
        $this->assertSame(AuditChain::GENESIS_PREV_HASH, $rows[0]->prev_hash);
        $this->assertSame($rows[0]->entry_hash, $rows[1]->prev_hash);
        $this->assertNotEmpty($rows[1]->signature);
    }

    public function test_an_intact_chain_verifies(): void
    {
        $this->artisan('workspace:audit:verify')->expectsOutputToContain('intact: 2 chained rows')->assertSuccessful();
    }

    public function test_a_row_changed_around_the_application_is_detected(): void
    {
        // Someone with database access switches the append-only guard off and edits a row.
        DB::statement('ALTER TABLE audit_log DISABLE TRIGGER audit_log_append_only');
        DB::table('audit_log')->where('seq', 0)->update(['ip' => '10.6.6.6']);
        DB::statement('ALTER TABLE audit_log ENABLE TRIGGER audit_log_append_only');

        $this->artisan('workspace:audit:verify')->expectsOutputToContain('broken at row 0')->assertFailed();
    }

    public function test_a_removed_row_is_detected(): void
    {
        DB::statement('ALTER TABLE audit_log DISABLE TRIGGER audit_log_append_only');
        DB::table('audit_log')->where('seq', 0)->delete();
        DB::statement('ALTER TABLE audit_log ENABLE TRIGGER audit_log_append_only');

        $this->artisan('workspace:audit:verify')->expectsOutputToContain('a row is missing')->assertFailed();
    }

    public function test_without_the_signing_key_nothing_changes(): void
    {
        config(['workspace.audit_signing_key' => storage_path('framework/testing/no-such-key.sk')]);
        AuditChain::flush();

        try {
            app(AccountService::class)->create($this->institution, 'Another', 'another@example.org', 'correct horse battery staple');
            $this->fail('the change should have been refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('signing key is not readable', $e->getMessage());
        }
        $this->assertFalse(User::where('email', 'another@example.org')->exists());
    }

    public function test_the_database_refuses_an_unchained_row(): void
    {
        $this->expectExceptionMessage('audit_log_chained');
        DB::table('audit_log')->insert([
            'id' => (string) Str::uuid7(),
            'action_id' => DB::table('audit_log')->value('action_id'),
        ]);
    }
}
