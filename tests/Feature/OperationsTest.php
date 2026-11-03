<?php

// OpenRiC Workspace - operations (roadmap phase 12a, ADR 0021): fixity schedules that check what they cover, the
// longest unchecked first and within their limits, every check in the ledger, a changed or missing file kept open and
// its schedule's account told until it passes again; the installation's figures for its operators and, with the token,
// as Prometheus text; failed background jobs tried again or forgotten; and encrypted fields encrypted again after the
// application key is rotated.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\AgentService;
use App\Description\InstantiationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Files\FileStore;
use App\Fixity\Fixity;
use App\Mail\FixityMail;
use App\Models\DigitalFile;
use App\Models\FixityCheck;
use App\Models\FixityFailure;
use App\Models\FixitySchedule;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use App\Models\Vendor;
use App\Operations\Health;
use App\Vendors\Vendors;
use App\Vocabulary\SkosImporter;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Imagick;
use ImagickPixel;
use InvalidArgumentException;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private string $store;

    private Institution $institution;

    private User $admin;

    private RecordResource $fonds;

    private RecordResource $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->admin = app(AccountService::class)->create($this->institution, 'Admin', 'admin@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->admin, 'administrator');
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $records = app(RecordService::class);
        $this->fonds = $records->create($this->institution, self::LEVEL.'fonds', 'Riverside Parish Council records', 'en', null, $holder, 'RPC');
        $letter = $records->create($this->institution, self::LEVEL.'item', 'Letter of 3 May 1902', 'en', $this->fonds, null, '1');
        $map = $records->create($this->institution, self::LEVEL.'item', 'Map of the commonage', 'en', $this->fonds, null, '2');
        $this->other = $records->create($this->institution, self::LEVEL.'fonds', 'Town Hall records', 'en', null, $holder, 'TH');
        foreach ([[$letter, '#d8c9a3'], [$map, '#a3c9d8'], [$this->other, '#c9d8a3']] as [$record, $colour]) {
            app(InstantiationService::class)->upload($record, $this->image($colour), 'scan.png', 'en', 'Scan', null, 'online', $this->admin);
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->store)) {
            exec('rm -rf '.escapeshellarg($this->store));
        }
        parent::tearDown();
    }

    private function image(string $colour): string
    {
        $image = new Imagick;
        $image->newImage(40, 30, new ImagickPixel($colour));
        $image->setImageFormat('png');
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($path, $image->getImageBlob());

        return $path;
    }

    /** The masters of the units inside the fonds. */
    private function masters()
    {
        return DigitalFile::whereHas('use', fn ($q) => $q->where('notation', 'master'))
            ->whereHas('instantiation', fn ($q) => $q->whereIn('record_resource_id', $this->fonds->descendantIds()))->orderBy('id')->get();
    }

    public function test_a_schedule_checks_what_it_covers_keeps_failures_open_and_tells_its_account(): void
    {
        Mail::fake();
        $fixity = app(Fixity::class);
        $schedule = $fixity->saveSchedule($this->institution, ['name' => 'Council records', 'scope' => 'record-set', 'record_resource_id' => $this->fonds->id,
            'frequency' => 'weekly', 'notify_user_id' => $this->admin->id, 'is_enabled' => 1], $this->admin);
        $covered = $fixity->files($this->institution->id, $schedule->fresh())->count();
        $this->assertSame(DigitalFile::whereHas('instantiation', fn ($q) => $q->whereIn('record_resource_id', $this->fonds->descendantIds()))->count(), $covered, 'the fonds and what is inside it, not the Town Hall');
        $this->assertLessThan(DigitalFile::count(), $covered);

        // One master changed, one gone.
        [$letter, $map] = $this->masters()->all();
        $store = app(FileStore::class);
        $original = file_get_contents($store->path($letter->storage_path));
        file_put_contents($store->path($letter->storage_path), 'not the letter');
        unlink($store->path($map->storage_path));

        $this->assertSame(1, $fixity->runDue(), 'a new schedule is due at once');
        $run = $schedule->fresh()->runs()->first();
        $this->assertSame(['completed', $covered, 1, 1], [$run->status->notation, $run->files_checked, $run->files_changed, $run->files_missing]);
        $this->assertSame(['mismatch', 'missing'], FixityFailure::with('outcome')->get()->sortBy(fn ($f) => $f->digital_file_id === $letter->id ? 0 : 1)->map(fn ($f) => $f->outcome->notation)->values()->all());
        $this->assertNotNull($letter->fresh()->fixity_failed_at);
        Mail::assertQueued(FixityMail::class, fn ($m) => $m->run->is($run));
        $this->assertSame(0, $fixity->runDue(), 'not due again until next week');
        $this->assertTrue($schedule->fresh()->next_run_at->isAfter(now()->addDays(6)));

        // Checked again: still failing, and counted.
        $fixity->run($this->institution, $schedule->fresh(), 'manual', $this->admin);
        $this->assertSame(2, FixityFailure::where('digital_file_id', $letter->id)->sole()->consecutive_failures);

        // Restored: the next check passes and closes the failure by itself.
        file_put_contents($store->path($letter->storage_path), $original);
        $this->assertTrue(app(InstantiationService::class)->checkFixity($letter->fresh(), $this->admin));
        $closed = FixityFailure::where('digital_file_id', $letter->id)->with('status')->sole();
        $this->assertSame('resolved', $closed->status->notation);
        $this->assertSame(3, FixityCheck::where('digital_file_id', $letter->id)->count(), 'every check kept');

        // A failure is closed by hand only with a reason.
        $missing = FixityFailure::where('digital_file_id', $map->id)->sole();
        try {
            $fixity->updateFailure($missing, 'ignored', '', $this->admin);
            $this->fail('closed without a reason');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Say why', $e->getMessage());
        }
        $fixity->updateFailure($missing, 'acknowledged', null, $this->admin);
        $this->assertSame($this->admin->id, $missing->fresh()->acknowledged_by_id);
    }

    public function test_a_run_stops_at_its_limit_and_takes_the_longest_unchecked_first(): void
    {
        $fixity = app(Fixity::class);
        $schedule = $fixity->saveSchedule($this->institution, ['name' => 'Two at a time', 'scope' => 'all', 'frequency' => 'daily', 'batch_size' => '2', 'is_enabled' => 1], $this->admin);
        // Times are kept to the second: a check in the second of the upload would tie with it.
        $this->travel(1)->minutes();
        $first = $fixity->run($this->institution, $schedule->fresh(), 'manual');
        $this->travel(1)->minutes();
        $this->assertSame(['partial', 2, 2], [$first->status->notation, $first->files_checked, $first->files_passed], $first->checks()->with('outcome', 'file.use')->get()->map(fn ($c) => $c->file->use->notation.' '.$c->outcome->notation.' '.$c->expected_size.'/'.$c->found_size)->join('; '));
        $second = $fixity->run($this->institution, $schedule->fresh(), 'manual');
        $this->assertSame(0, $second->checks()->whereIn('digital_file_id', $first->checks()->pluck('digital_file_id'))->count(), 'the files the first run did not reach');
        $this->assertSame(4, FixityCheck::distinct()->count('digital_file_id'));
    }

    public function test_the_figures_are_for_operators_and_for_a_monitor_with_the_token(): void
    {
        $this->assertTrue(Health::operator($this->admin), 'one institution: who manages it operates it');
        $editor = app(AccountService::class)->create($this->institution, 'Editor', 'editor@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($editor, 'editor');
        $this->assertFalse(Health::operator($editor));
        config(['workspace.operators' => 'someone@example.org']);
        $this->assertFalse(Health::operator($this->admin), 'named operators only, once any are named');
        config(['workspace.operators' => 'ADMIN@example.org']);
        $this->assertTrue(Health::operator($this->admin));

        $health = app(Health::class);
        Cache::forget('workspace:scheduler:heartbeat');
        $figures = $health->figures();
        $this->assertSame(DigitalFile::count(), $figures['files_total'][0]);
        $this->assertArrayHasKey('scheduler_heartbeat_age_seconds', $health->problems($figures), 'the scheduler never ran');
        Cache::forever('workspace:scheduler:heartbeat', now()->getTimestamp());
        $this->assertArrayNotHasKey('scheduler_heartbeat_age_seconds', $health->problems($health->figures()));

        $this->get('/metrics')->assertNotFound();
        config(['workspace.metrics_token' => 'scrape-secret']);
        $this->get('/metrics')->assertUnauthorized();
        $text = $this->withToken('scrape-secret')->get('/metrics')->assertOk()->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')->getContent();
        $this->assertStringContainsString("openric_workspace_up 1\n", $text);
        $this->assertMatchesRegularExpression('/^openric_workspace_files_total '.DigitalFile::count().'$/m', $text);
        $this->assertStringContainsString('# TYPE openric_workspace_queue_failed_jobs gauge', $text);
        config(['workspace.metrics_ips' => '10.0.0.0/8']);
        $this->withToken('scrape-secret')->get('/metrics')->assertForbidden();

        $this->actingAs($this->admin)->get(route('operations.index'))->assertOk()->assertSee('queue_pending_jobs')->assertSee('Background jobs that failed');
        $this->actingAs($editor)->get(route('operations.index'))->assertForbidden();
    }

    public function test_failed_jobs_are_tried_again_or_forgotten_and_the_pages_answer(): void
    {
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'payload' => json_encode(['displayName' => 'App\\Jobs\\DeliverWebhook', 'uuid' => $uuid]),
            'exception' => "RuntimeException: the receiver was down\n#0 trace", 'failed_at' => now()]);
        $this->actingAs($this->admin)->get(route('operations.index'))->assertOk()->assertSee('App\\Jobs\\DeliverWebhook')->assertSee('the receiver was down')->assertDontSee('#0 trace');
        $this->actingAs($this->admin)->post(route('operations.failed-job', [$uuid, 'forget']))->assertRedirect(route('operations.index'));
        $this->assertSame(0, DB::table('failed_jobs')->count());

        $this->actingAs($this->admin)->post(route('fixity.schedules.store'), ['name' => 'Everything', 'scope' => 'all', 'frequency' => 'monthly', 'is_enabled' => 1])->assertRedirect(route('fixity.schedules'));
        $schedule = FixitySchedule::sole();
        $this->actingAs($this->admin)->post(route('fixity.schedules.store'), ['name' => 'Bad', 'scope' => 'repository'])->assertSessionHasErrors('fixity');
        $this->actingAs($this->admin)->post(route('fixity.schedules.run', $schedule))->assertRedirect();
        $run = $schedule->fresh()->runs()->first();
        $this->assertSame(DigitalFile::count(), $run->files_checked);
        $this->actingAs($this->admin)->get(route('fixity.schedules'))->assertOk()->assertSee('Everything');
        $this->actingAs($this->admin)->get(route('fixity.run', $run))->assertOk()->assertSee('scan.png');
        $this->actingAs($this->admin)->get(route('fixity.failures'))->assertOk()->assertSee('None.');
        $this->assertSame(0, $this->artisan('workspace:fixity')->run());
    }

    public function test_encrypted_fields_are_encrypted_again_with_a_new_application_key(): void
    {
        $agent = app(AgentService::class)->create($this->institution, 'corporate-body', 'Bindery', 'en');
        $vendor = app(Vendors::class)->create($agent, ['bank_account_number' => '62001234567'], $this->admin);
        $before = DB::table('vendors')->where('id', $vendor->id)->value('bank_account_number');
        $old = config('app.key');
        $new = 'base64:'.base64_encode(Encrypter::generateKey(config('app.cipher')));
        config(['app.key' => $new, 'app.previous_keys' => [$old]]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        $this->artisan('workspace:keys:reencrypt')->expectsOutputToContain('values encrypted again, 0 not opened')->assertSuccessful();
        $after = DB::table('vendors')->where('id', $vendor->id)->value('bank_account_number');
        $this->assertNotSame($before, $after);
        config(['app.previous_keys' => []]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        $this->assertSame('62001234567', Vendor::find($vendor->id)->bank_account_number, 'opened by the new key alone');
    }
}
