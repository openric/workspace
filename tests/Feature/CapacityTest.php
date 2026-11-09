<?php

// OpenRiC Workspace - the capacity test's commands (roadmap R1 hardening): neither runs on a database whose name does not
// end in _capacity, so a real Workspace is never filled with synthetic records; a small capacity archive is made through
// the application's own services, published but for its drafts, with its widest fonds, deepest unit and scans, and the
// targets k6 visits are written from it; a spreadsheet import is timed.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\RepositoryService;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Support\Vocab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CapacityTest extends TestCase
{
    use RefreshDatabase;

    private string $out;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        $institution = Institution::create(['name' => 'Capacity Test Archive', 'base_iri' => 'https://capacity.example.org']);
        app(RoleService::class)->seedDefaults($institution);
        $admin = app(AccountService::class)->create($institution, 'Admin', 'admin@capacity.invalid', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($admin, 'administrator');
        app(RepositoryService::class)->create($institution, 'Capacity Test Archive', 'en', 'CTA');
        $this->out = tempnam(sys_get_temp_dir(), 'targets');
    }

    protected function tearDown(): void
    {
        @unlink($this->out);
        parent::tearDown();
    }

    public function test_neither_command_runs_on_a_database_that_is_not_for_capacity(): void
    {
        $this->artisan('workspace:capacity:seed', ['--password' => 'correct horse battery staple'])->expectsOutputToContain('Refused')->assertFailed();
        $this->artisan('workspace:capacity:probe', ['action' => 'targets'])->expectsOutputToContain('Refused')->assertFailed();
        $this->assertSame(0, RecordResource::count());
    }

    public function test_a_small_capacity_archive_is_made_and_its_targets_written(): void
    {
        // Only the name is read: the test database stands in for a capacity one.
        config(['database.connections.'.config('database.default').'.database' => 'openric_workspace_test_capacity']);
        $this->artisan('workspace:capacity:seed', ['--password' => 'correct horse battery staple', '--units' => 200, '--agents' => 30, '--places' => 10,
            '--activities' => 5, '--files' => 1])->assertSuccessful();
        $this->assertSame(200, RecordResource::count());
        $this->artisan('workspace:capacity:seed', ['--password' => 'correct horse battery staple'])->expectsOutputToContain('kept, not loaded twice')->assertFailed();

        $this->artisan('workspace:capacity:probe', ['action' => 'targets', '--out' => $this->out])->assertSuccessful();
        $targets = json_decode(file_get_contents($this->out), true);
        $this->assertSame(20, RecordResource::where('parent_id', $targets['wide_fonds'])->count(), 'the widest fonds: a tenth of the units');
        $depth = 0;
        for ($unit = RecordResource::find($targets['deep_unit']); $unit; $unit = $unit->parent) {
            $depth++;
        }
        $this->assertSame(8, $depth, 'the deepest branch');
        $this->assertNotEmpty($targets['records']);
        $this->assertSame(1, $targets['counts']['instantiations']);
        $drafts = DB::table('names')->where('subject_table', 'record_resources')->whereNotIn('subject_id', DB::table('names')->where('subject_table', 'record_resources')
            ->whereIn('state_id', DB::table('concepts')->where('iri', Vocab::valueState('published'))->select('id'))->select('subject_id'))->distinct()->count('subject_id');
        $this->assertSame(10, $drafts, 'one unit in twenty left as a draft');
        $this->get('/discover/records/'.$targets['records'][0])->assertOk();

        $this->artisan('workspace:capacity:probe', ['action' => 'import', '--rows' => 5])->expectsOutputToContain('commit:')->assertSuccessful();
        $this->assertSame(206, RecordResource::count(), 'a file and its five items imported');
    }
}
