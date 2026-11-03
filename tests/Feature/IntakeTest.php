<?php

// OpenRiC Workspace - watched folders and the scan API (roadmap phase 22a): scans grouped by stem with their Heratio
// sidecar, a table imported, a bag checked against its manifest, a tampered bag refused, what was taken in moved
// aside and logged, a folder outside the intake root refused, and a capture station posting an item's pages.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Accounts\ApiKeyService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Import;
use App\Models\Instantiation;
use App\Models\Institution;
use App\Models\IntakeFile;
use App\Models\RecordResource;
use App\Models\User;
use App\Models\WatchFolder;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Imagick;
use ImagickPixel;
use Tests\TestCase;

class IntakeTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private string $store;

    private string $root;

    private Institution $institution;

    private User $officer;

    private RecordResource $fonds;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        $this->root = sys_get_temp_dir().'/workspace-intake-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/scanner', 0770, true);
        config(['workspace.object_store' => $this->store, 'workspace.intake_root' => $this->root, 'workspace.intake_settle_seconds' => 0]);
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->officer = app(AccountService::class)->create($this->institution, 'Officer', 'officer@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->officer, 'administrator');
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $this->fonds = app(RecordService::class)->create($this->institution, self::LEVEL.'fonds', 'Council records', 'en', null, $holder, 'C');
    }

    protected function tearDown(): void
    {
        foreach ([$this->store, $this->root] as $dir) {
            if (is_dir($dir)) {
                exec('rm -rf '.escapeshellarg($dir));
            }
        }
        parent::tearDown();
    }

    private function png(string $colour): string
    {
        $image = new Imagick;
        $image->newImage(30, 20, new ImagickPixel($colour));
        $image->setImageFormat('png');

        return $image->getImageBlob();
    }

    private function bag(string $dir, bool $tamper): void
    {
        mkdir("$dir/data", 0770, true);
        file_put_contents("$dir/bagit.txt", "BagIt-Version: 1.0\nTag-File-Character-Encoding: UTF-8\n");
        file_put_contents("$dir/data/plan.png", $png = $this->png('#334455'));
        file_put_contents("$dir/manifest-sha256.txt", hash('sha256', $png)."  data/plan.png\n");
        if ($tamper) {
            file_put_contents("$dir/data/plan.png", $this->png('#ff0000'));
        }
    }

    public function test_a_watched_folder_takes_in_scans_tables_and_bags(): void
    {
        $this->actingAs($this->officer)->post(route('intake.store'), ['label' => 'Escape', 'path' => '../etc', 'format' => 'csv', 'language' => 'en',
            'repository_id' => $this->fonds->repository_id, 'level' => 'item', 'user_id' => $this->officer->id])->assertSessionHasErrors('intake');
        $this->actingAs($this->officer)->post(route('intake.store'), ['label' => 'Book scanner', 'path' => 'scanner', 'format' => 'csv', 'language' => 'en',
            'repository_id' => $this->fonds->repository_id, 'parent_iri' => $this->fonds->iri, 'level' => 'item', 'user_id' => $this->officer->id,
            'auto_commit' => 1, 'is_enabled' => 1])->assertSessionHasNoErrors();
        $folder = WatchFolder::sole();

        $dir = $this->root.'/scanner';
        // Two pages of one minute book, with a Heratio sidecar; a single photograph with none.
        file_put_contents("$dir/MB-1902_p1.png", $this->png('#111111'));
        file_put_contents("$dir/MB-1902_p2.png", $this->png('#222222'));
        file_put_contents("$dir/MB-1902.xml", '<?xml version="1.0"?><heratioScan xmlns="https://heratio.io/scan/v1"><title>Minute book, 1902</title><identifier>MB-1902</identifier>'
            .'<scopeAndContent>Minutes of the council.</scopeAndContent><dates><date start="1902-01-01" end="1902-12-31"/></dates></heratioScan>');
        file_put_contents("$dir/bridge.png", $this->png('#999999'));
        // A table in AtoM's columns, a good bag and a tampered one.
        file_put_contents("$dir/letters.csv", "legacyId,title,levelOfDescription,identifier\nL1,Letter to the mayor,item,L-1\n");
        $this->bag("$dir/plans-bag", false);
        $this->bag("$dir/bad-bag", true);

        Artisan::call('workspace:watch-folders');

        $book = RecordResource::where('parent_id', $this->fonds->id)->get()->first(fn ($r) => $r->identifier === 'MB-1902');
        $this->assertNotNull($book, 'the sidecar named the record');
        $this->assertSame('Minute book, 1902', $book->described('title', ['en']));
        $this->assertSame('1902-01-01/1902-12-31', $book->dates()->sole()->normalized_value);
        $this->assertSame(['MB-1902_p1.png', 'MB-1902_p2.png'], Instantiation::where('record_resource_id', $book->id)->orderBy('position')->get()
            ->map(fn ($i) => $i->files()->whereHas('use', fn ($q) => $q->where('notation', 'master'))->first()->original_name)->all());
        $titles = RecordResource::where('parent_id', $this->fonds->id)->get()->map(fn ($r) => $r->described('title', ['en']))->all();
        $this->assertContains('bridge', $titles, 'a file with no sidecar is titled by its name');
        $this->assertContains('plan', $titles, 'the bag\'s file');
        $this->assertContains('Letter to the mayor', $titles, 'the table, imported and committed');
        $this->assertNotNull(Import::sole()->committed_at);

        // What was taken in moved aside; the tampered bag refused with its reason beside it.
        $this->assertSame([], array_values(array_filter(scandir($dir), fn ($e) => $e[0] !== '.')));
        $failed = glob("$dir/.failed/*/bad-bag.error.txt")[0] ?? null;
        $this->assertNotNull($failed);
        $this->assertStringContainsString('the bag does not match its manifest: data/plan.png', file_get_contents($failed));
        $outcomes = IntakeFile::with('outcome')->get()->groupBy(fn ($f) => $f->outcome->notation)->map->count()->all();
        $this->assertEquals(['item-created' => 4, 'imported' => 1, 'failed' => 1], $outcomes);
        $this->assertSame(6, $folder->fresh()->files_taken);
        $this->actingAs($this->officer)->get(route('intake.index'))->assertOk()->assertSee('Minute book, 1902')->assertSee('the bag does not match its manifest');
    }

    public function test_the_scan_api_makes_a_record_of_an_items_pages(): void
    {
        [, $read] = app(ApiKeyService::class)->issue($this->officer, 'Scanner', ['read'], null, null, $this->officer);
        [, $write] = app(ApiKeyService::class)->issue($this->officer, 'Scanner', ['read', 'write'], null, null, $this->officer);
        $post = fn (string $key, array $data) => $this->post('https://archives.example.org/api/ric/v1/intake', $data, ['X-API-Key' => $key, 'Accept' => 'application/json']);
        $pages = [UploadedFile::fake()->createWithContent('deed_p2.png', $this->png('#123123')), UploadedFile::fake()->createWithContent('deed_p1.png', $this->png('#321321'))];
        $post($read, ['parent' => $this->fonds->iri, 'files' => $pages])->assertForbidden();
        $post($write, ['parent' => 'https://nowhere.example.org/x', 'files' => $pages])->assertStatus(422);
        $response = $post($write, ['parent' => $this->fonds->iri, 'title' => 'Deed of transfer', 'identifier' => 'D-7', 'date' => '1911', 'files' => $pages])->assertCreated();
        $record = RecordResource::findOrFail($response->json('id'));
        $this->assertSame([$this->fonds->id, 'Deed of transfer', 'D-7'], [$record->parent_id, $record->described('title', ['en']), $record->identifier]);
        $this->assertSame(['deed_p1.png', 'deed_p2.png'], Instantiation::where('record_resource_id', $record->id)->orderBy('position')->get()
            ->map(fn ($i) => $i->files()->whereHas('use', fn ($q) => $q->where('notation', 'master'))->first()->original_name)->all(), 'pages in their order');
        $this->assertSame(2, IntakeFile::whereNotNull('api_key_id')->count());
    }
}
