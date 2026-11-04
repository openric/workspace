<?php

// OpenRiC Workspace - OCFL as the one object store (roadmap phase 15b, ADR 0022): an instantiation's files are one OCFL
// 1.1 object named by its IRI, laid out by extension 0004; each change is a new version holding the whole object; every
// object verifies; a disposal purges the object; and files kept in the old layout are moved into OCFL by a command.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\InstantiationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Files\FileStore;
use App\Files\Ocfl\StorageRoot;
use App\Models\Institution;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Imagick;
use ImagickPixel;
use Tests\TestCase;

class OcflStoreTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private string $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-ocfl-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
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
        $image->newImage(80, 60, new ImagickPixel($colour));
        $image->setImageFormat('png');
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($path, $image->getImageBlob());

        return $path;
    }

    public function test_an_instantiation_is_an_ocfl_object_whose_versions_hold_the_whole_of_it(): void
    {
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($institution);
        $admin = app(AccountService::class)->create($institution, 'Admin', 'admin@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($admin, 'administrator');
        $holder = app(RepositoryService::class)->create($institution, 'Riverside Archives', 'en', 'RA');
        $letter = app(RecordService::class)->create($institution, self::LEVEL.'item', 'Letter of 3 May 1902', 'en', null, $holder, 'L1');
        $this->actingAs($admin);
        $scan = app(InstantiationService::class)->upload($letter, $this->image('#d8c9a3'), '../letter.png', 'en', 'Scan', null, 'online', $admin);
        $ocfl = app(FileStore::class)->ocfl();

        // The storage root declares itself and its layout.
        $this->assertSame("ocfl_1.1\n", file_get_contents($this->store.'/0=ocfl_1.1'));
        $this->assertSame(StorageRoot::EXTENSION, json_decode(file_get_contents($this->store.'/ocfl_layout.json'), true)['extension']);
        $h = hash('sha256', $scan->iri);
        $this->assertSame(substr($h, 0, 3).'/'.substr($h, 3, 3).'/'.substr($h, 6, 3).'/'.$h, StorageRoot::objectRoot($scan->iri), 'extension 0004');
        foreach ($scan->files as $file) {
            $this->assertStringStartsWith(StorageRoot::objectRoot($scan->iri).'/v', $file->storage_path);
            $this->assertSame($file->sha256, hash_file('sha256', app(FileStore::class)->path($file->storage_path)));
        }

        // The master and its copies are all in the head version, each under its logical path.
        $inventory = $ocfl->inventory($scan->iri);
        $this->assertSame($scan->iri, $inventory->id);
        $head = array_keys($inventory->headVersion()->paths());
        $this->assertContains('files/master/letter.png', $head, 'the name without its folders');
        $this->assertContains('files/reference/reference.jpg', $head);
        $this->assertContains('files/thumbnail/thumbnail.jpg', $head);
        $versions = count($inventory->versions);

        // A copy given again is a new version (one for each size made) that still holds the master.
        app(InstantiationService::class)->replaceCopy($scan->fresh(), 'thumbnail', $this->image('#203040'), $admin);
        $inventory = $ocfl->inventory($scan->iri);
        $this->assertGreaterThan($versions, count($inventory->versions));
        $this->assertContains('files/master/letter.png', array_keys($inventory->headVersion()->paths()), 'the whole object in each version');
        $this->assertSame([], $ocfl->verify($scan->iri));
        $this->artisan('workspace:ocfl:verify')->assertSuccessful();

        // A changed content file is found.
        $master = $scan->fresh()->file('master');
        file_put_contents(app(FileStore::class)->path($master->storage_path), 'not the letter');
        $this->artisan('workspace:ocfl:verify')->assertFailed();
        $this->assertNotEmpty($ocfl->verify($scan->iri));

        // Purged, every version goes.
        $ocfl->purge($scan->iri);
        $this->assertFalse($ocfl->exists($scan->iri));
        $this->assertDirectoryDoesNotExist($this->store.'/'.substr($h, 0, 3));
    }

    public function test_files_kept_in_the_old_layout_are_moved_into_ocfl(): void
    {
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($institution);
        $admin = app(AccountService::class)->create($institution, 'Admin', 'admin@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($admin, 'administrator');
        $holder = app(RepositoryService::class)->create($institution, 'Riverside Archives', 'en', 'RA');
        $letter = app(RecordService::class)->create($institution, self::LEVEL.'item', 'Letter of 3 May 1902', 'en', null, $holder, 'L1');
        $scan = app(InstantiationService::class)->upload($letter, $this->image('#d8c9a3'), 'letter.png', 'en', 'Scan', null, 'online', $admin);

        // Put back as the Workspace kept files before 0.0.115: <institution>/<aa>/<bb>/<sha256>, with no OCFL.
        $store = app(FileStore::class);
        DB::statement("SET LOCAL workspace.moving_files = 'on'");
        foreach ($scan->files as $file) {
            $old = $institution->id.'/'.substr($file->sha256, 0, 2).'/'.substr($file->sha256, 2, 2).'/'.$file->sha256;
            is_dir(dirname($store->path($old))) || mkdir(dirname($store->path($old)), 0750, true);
            copy($store->path($file->storage_path), $store->path($old));
            DB::table('digital_files')->where('id', $file->id)->update(['storage_path' => $old]);
        }
        $store->ocfl()->purge($scan->iri);

        $this->artisan('workspace:ocfl:migrate')->assertSuccessful();
        foreach ($scan->fresh()->files as $file) {
            $this->assertStringStartsWith(StorageRoot::objectRoot($scan->iri).'/v1/content/files/', $file->storage_path);
            $this->assertSame($file->sha256, hash_file('sha256', $store->path($file->storage_path)));
        }
        $this->assertContains('files/master/letter.png', array_keys($store->ocfl()->inventory($scan->iri)->headVersion()->paths()));
        $this->assertDirectoryDoesNotExist($this->store.'/'.$institution->id, 'the old layout removed');
        $this->artisan('workspace:ocfl:verify')->assertSuccessful();
        $this->artisan('workspace:ocfl:migrate')->expectsOutput('0 files moved into 0 OCFL objects.')->assertSuccessful();
    }
}
