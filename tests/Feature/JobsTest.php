<?php

// OpenRiC Workspace - background jobs (roadmap phase 5): uploads make their viewing copies, and imports their dry runs
// and imports, in the background; a job's record follows it from waiting to done, failed or cancelled; each account
// sees its own jobs and one that manages the institution sees, clears and exports all of them.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Jobs\JobsService;
use App\Jobs\MakeViewingCopies;
use App\Jobs\RunImport;
use App\Models\BackgroundJob;
use App\Models\Import;
use App\Models\Instantiation;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Imagick;
use ImagickPixel;
use Tests\TestCase;

class JobsTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private string $store;

    private Institution $institution;

    private User $editor;

    private User $colleague;

    private User $admin;

    private Repository $holder;

    private RecordResource $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        $this->loadBundledVocabularies();
        $accounts = app(AccountService::class);
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        foreach (['editor' => 'editor', 'colleague' => 'editor', 'admin' => 'administrator'] as $who => $role) {
            $this->{$who} = $accounts->create($this->institution, ucfirst($who), "$who@example.org", 'correct horse battery staple');
            $accounts->grantTemplateRole($this->{$who}, $role);
        }
        $this->holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $this->item = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Minute book, 1902', 'en', null, $this->holder, 'MB1');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->store)) {
            exec('rm -rf '.escapeshellarg($this->store));
        }
        parent::tearDown();
    }

    private function scan(): UploadedFile
    {
        $image = new Imagick;
        $image->newImage(1200, 900, new ImagickPixel('#d8c9a3'));
        $image->setImageFormat('png');

        return UploadedFile::fake()->createWithContent('minute-book-scan.png', $image->getImageBlob());
    }

    private function csv(): string
    {
        return "legacyId,title,levelOfDescription,identifier\nF1,Riverside Parish Council records,Fonds,RPC\n";
    }

    private function statusOf(BackgroundJob $job): string
    {
        return $job->fresh()->status->notation;
    }

    public function test_an_upload_keeps_its_file_at_once_and_makes_its_viewing_copies_in_a_job(): void
    {
        Queue::fake();
        $this->actingAs($this->editor)->post("/records/{$this->item->id}/instantiations", ['file' => $this->scan(), 'language' => 'en', 'availability' => 'online'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $instantiation = Instantiation::where('record_resource_id', $this->item->id)->firstOrFail();
        $this->assertSame(['master'], $instantiation->files()->with('use')->get()->map(fn ($f) => $f->use->notation)->all(), 'only the file, kept and checked');
        $job = BackgroundJob::where(['subject_table' => 'instantiations', 'subject_id' => $instantiation->id])->firstOrFail();
        $this->assertSame('pending', $this->statusOf($job));
        $this->assertSame($this->editor->id, $job->user_id);

        Queue::assertPushed(MakeViewingCopies::class, fn ($queued) => $queued->jobId === $job->id);
        app()->call([new MakeViewingCopies($job->id, $instantiation->id), 'handle']);
        $this->assertSame('completed', $this->statusOf($job));
        $this->assertSame(['copies' => 2], $job->fresh()->output);
        $this->assertEqualsCanonicalizing(['master', 'reference', 'thumbnail'], $instantiation->files()->with('use')->get()->map(fn ($f) => $f->use->notation)->all());
        $this->assertNotNull($job->fresh()->started_at);
    }

    public function test_an_import_reads_its_file_in_a_job_and_its_page_waits_for_it(): void
    {
        Queue::fake();
        $location = $this->actingAs($this->editor)->post('/imports', ['file' => UploadedFile::fake()->createWithContent('fonds.csv', $this->csv()),
            'format' => 'csv', 'repository' => $this->holder->id, 'language' => 'en'])
            ->assertSessionHas('status', 'The dry run is under way. This page shows the report when it is done.')->headers->get('Location');
        $import = Import::findOrFail(basename($location));
        $page = $this->actingAs($this->editor)->get("/imports/{$import->id}")->assertOk()->assertSee('Waiting for a worker.')
            ->assertSee('<meta http-equiv="refresh" content="5">', false)->assertDontSee('Import now');
        $this->actingAs($this->editor)->post("/imports/{$import->id}/commit")->assertSessionHasErrors('import');

        $job = app(JobsService::class)->latestFor($import);
        app()->call([new RunImport($job->id, $import->id, false), 'handle']);
        $this->assertSame('completed', $this->statusOf($job));
        $this->assertEquals(['commit' => false, 'created' => 1, 'updated' => 0, 'unchanged' => 0, 'rejected' => 0], $job->fresh()->output);
        $this->assertSame(1, RecordResource::count(), 'a dry run writes nothing');
        $this->actingAs($this->editor)->get("/imports/{$import->id}")->assertSee('Import now')->assertDontSee('http-equiv="refresh"', false);

        $this->actingAs($this->editor)->post("/imports/{$import->id}/commit")->assertSessionHas('status', 'The import is under way. This page shows the report when it is done.');
        $commit = app(JobsService::class)->latestFor($import);
        $this->assertNotSame($job->id, $commit->id);
        app()->call([new RunImport($commit->id, $import->id, true), 'handle']);
        $this->assertSame(2, RecordResource::count());
        $this->assertNotNull($import->fresh()->committed_at);
    }

    public function test_without_a_separate_worker_the_work_is_done_before_the_page_answers(): void
    {
        $this->actingAs($this->editor)->post('/imports', ['file' => UploadedFile::fake()->createWithContent('fonds.csv', $this->csv()),
            'format' => 'csv', 'repository' => $this->holder->id, 'language' => 'en'])
            ->assertSessionHas('status', 'Dry run done: nothing has been written yet. Read the report, then import.');
        $this->assertSame(['completed'], BackgroundJob::with('status')->get()->map(fn ($j) => $j->status->notation)->all());
    }

    public function test_a_cancelled_job_is_skipped_and_a_failing_one_keeps_its_error(): void
    {
        $jobs = app(JobsService::class);
        $cancelled = $jobs->create($this->institution->id, 'viewing-copies', 'Viewing copies of a.png', $this->editor);
        $this->actingAs($this->editor)->post("/jobs/{$cancelled->id}/cancel")->assertSessionHas('status', 'Cancelled.');
        app()->call([new MakeViewingCopies($cancelled->id, '00000000-0000-0000-0000-000000000000'), 'handle']);
        $this->assertSame('cancelled', $this->statusOf($cancelled));
        $this->assertNull($cancelled->fresh()->started_at, 'never started');

        $failing = $jobs->create($this->institution->id, 'viewing-copies', 'Viewing copies of b.png', $this->editor);
        app()->call([new MakeViewingCopies($failing->id, '00000000-0000-0000-0000-000000000000'), 'handle']);
        $this->assertSame('failed', $this->statusOf($failing));
        $this->assertStringContainsString('No query results', (string) $failing->fresh()->error_message);
        $this->actingAs($this->editor)->post("/jobs/{$failing->id}/cancel")->assertSessionHas('status', 'Only a job that is still waiting can be cancelled.');
        $this->actingAs($this->editor)->get("/jobs/{$failing->id}")->assertOk()->assertSee('No query results')->assertDontSee('Stack trace');
        $this->actingAs($this->admin)->get("/jobs/{$failing->id}")->assertOk()->assertSee('Stack trace');
    }

    public function test_each_account_sees_its_own_jobs_and_an_administrator_all_of_them(): void
    {
        $jobs = app(JobsService::class);
        $mine = $jobs->create($this->institution->id, 'import', 'Dry run of mine.csv', $this->editor);
        $theirs = $jobs->create($this->institution->id, 'import', 'Dry run of theirs.csv', $this->colleague);
        $this->actingAs($this->editor)->get('/jobs')->assertOk()->assertSee('Dry run of mine.csv')->assertDontSee('Dry run of theirs.csv')->assertDontSee('Export as CSV');
        $this->actingAs($this->editor)->get("/jobs/{$theirs->id}")->assertNotFound();
        $this->actingAs($this->editor)->post("/jobs/{$theirs->id}/cancel")->assertNotFound();
        $this->actingAs($this->editor)->get('/jobs/export.csv')->assertForbidden();
        $this->actingAs($this->editor)->post('/jobs/clear-inactive')->assertForbidden();

        $this->actingAs($this->admin)->get('/jobs')->assertOk()->assertSee('Dry run of mine.csv')->assertSee('Dry run of theirs.csv')
            ->assertSee('2 jobs: 2 waiting, 0 running, 0 completed, 0 failed, 0 cancelled.');
        $this->actingAs($this->admin)->get('/jobs?status=pending&type=import')->assertSee('Dry run of theirs.csv');
        $this->actingAs($this->admin)->get('/jobs?status=failed')->assertDontSee('Dry run of theirs.csv');
        $csv = $this->actingAs($this->admin)->get('/jobs/export.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('Dry run of theirs.csv', $csv);

        $jobs->complete($mine);
        BackgroundJob::whereKey($mine->id)->update(['completed_at' => now()->subDays(31)]);
        $this->actingAs($this->admin)->post('/jobs/clear-inactive')->assertSessionHas('status', '1 finished job older than 30 days cleared.');
        $this->assertNull($mine->fresh());
        $this->assertNotNull($theirs->fresh(), 'a waiting job stays');
    }
}
