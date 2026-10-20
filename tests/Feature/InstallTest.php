<?php

// OpenRiC Workspace - first installation (WS-001).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\Repository;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InstallTest extends TestCase
{
    use RefreshDatabase;

    public function test_install_creates_the_institution_grants_and_administrator(): void
    {
        $this->artisan('workspace:install', [
            '--institution' => 'Riverside Parish Council Archives',
            '--base-iri' => 'https://archives.example.org/',
            '--admin-name' => 'The Administrator',
            '--admin-email' => 'Admin@Example.org',
            '--skip-openric-vocabularies' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        $admin = User::where('email', 'admin@example.org')->firstOrFail();
        $this->assertSame('https://archives.example.org', $admin->institution->base_iri);
        $repository = Repository::firstOrFail();
        $this->assertStringStartsWith('https://archives.example.org/repository/', $repository->iri);
        $this->assertSame('Riverside Parish Council Archives', $repository->described('authorised-name', ['en']));
        $this->assertTrue($admin->hasPermission(Vocab::permission('manage-accounts')));
        $this->assertFalse($admin->hasPermission(Vocab::permission('publish')));
        $this->assertTrue(DB::table('audit_log as a')->join('concepts as c', 'c.id', '=', 'a.action_id')->where('c.notation', 'install')->exists());

        $this->artisan('workspace:install', ['--no-interaction' => true])->assertFailed();
    }

    public function test_install_refuses_a_base_iri_that_is_not_https(): void
    {
        $this->artisan('workspace:install', [
            '--institution' => 'X', '--base-iri' => 'http://archives.example.org', '--admin-name' => 'A',
            '--admin-email' => 'a@example.org', '--skip-openric-vocabularies' => true, '--no-interaction' => true,
        ])->assertExitCode(2);
        $this->assertFalse(Institution::exists());
    }
}
