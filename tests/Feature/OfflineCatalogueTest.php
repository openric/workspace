<?php

// OpenRiC Workspace - offline catalogues (roadmap phase 11c), after Heratio's ahg-portable-export: a read-only package
// holds what the public may read (a published unit and its reference copy and thumbnail, never a draft or a master),
// its viewer, its RiC-O, its checksums and what it withheld and left out; an archive holds what its maker may read,
// masters too, and is never given a link; a link to a read-only package downloads it until it is used up or revoked;
// the exchange file an editable package's viewer writes is taken back as a draft submission; making them is a
// permission, and another institution's package does not exist.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\InstantiationService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Files\FileStore;
use App\Models\Import;
use App\Models\Institution;
use App\Models\OfflineCatalogue;
use App\Models\RecordResource;
use App\Models\User;
use App\Research\Submissions;
use App\Search\SearchIndex;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Imagick;
use ImagickPixel;
use Tests\TestCase;
use ZipArchive;

class OfflineCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private Institution $institution;

    private User $admin;

    private User $editor;

    private string $store;

    private RecordResource $fonds;

    private RecordResource $letter;

    private RecordResource $draft;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-11c-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://riverside.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $accounts = app(AccountService::class);
        $this->admin = $accounts->create($this->institution, 'Admin', 'admin@riverside.example.org', 'correct horse battery staple');
        $accounts->grantTemplateRole($this->admin, 'administrator');
        $this->editor = $accounts->create($this->institution, 'Editor', 'editor@riverside.example.org', 'correct horse battery staple');
        $accounts->grantTemplateRole($this->editor, 'editor');

        $this->actingAs($this->admin);
        $records = app(RecordService::class);
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $this->fonds = $records->create($this->institution, self::LEVEL.'fonds', 'Riverside Town Council records', 'en', null, $holder, 'RTC');
        $this->letter = $records->create($this->institution, self::LEVEL.'item', 'Letter of 3 May 1902', 'en', $this->fonds, $holder, 'L1');
        $records->describe($this->letter, ['scope-and-content' => 'A letter about the water supply.'], 'en');
        $this->draft = $records->create($this->institution, self::LEVEL.'item', 'A draft letter', 'en', $this->fonds, $holder, 'L2');
        $image = new Imagick;
        $image->newImage(80, 60, new ImagickPixel('#d8c9a3'));
        $image->setImageFormat('png');
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($path, $image->getImageBlob());
        $scan = app(InstantiationService::class)->upload($this->letter, $path, 'letter.png', 'en', 'Scan', null, 'online', $this->admin);
        foreach ([$holder, $this->fonds, $this->letter, $scan] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->admin);
        }
        app(SearchIndex::class)->flush();
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->store));
        parent::tearDown();
    }

    private function make(User $user, array $data): OfflineCatalogue
    {
        $this->actingAs($user)->post(route('offline-catalogues.store'), $data + ['title' => 'Riverside', 'scope' => 'everything', 'mode' => 'read-only', 'include_files' => '1'])
            ->assertRedirect(route('offline-catalogues.index'))->assertSessionHasNoErrors();

        return OfflineCatalogue::latest('created_at')->firstOrFail()->load('status', 'mode');
    }

    private function open(OfflineCatalogue $catalogue): ZipArchive
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(app(FileStore::class)->path($catalogue->storage_path)) === true);

        return $zip;
    }

    /** @return list<string> */
    private function names(ZipArchive $zip): array
    {
        return array_map(fn ($i) => (string) $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
    }

    public function test_a_read_only_package_holds_what_the_public_may_read_with_its_viewer_checksums_and_ric_o(): void
    {
        $catalogue = $this->make($this->editor, []);
        $this->assertSame('completed', $catalogue->status->notation, (string) $catalogue->error);
        $this->assertSame([2, 2], [$catalogue->record_count, $catalogue->file_count], 'the fonds and the letter; the letter\'s reference copy and thumbnail');
        $zip = $this->open($catalogue);
        $names = $this->names($zip);
        foreach (['index.html', 'viewer.js', 'viewer.css', 'verify.html', 'verify.sh', 'README.txt', 'SHA256SUMS', 'left-out.csv', 'data/catalogue.js', 'data/catalogue.json',
            'data/catalogue.jsonld', 'data/manifest.json', 'data/disclosure-summary.json', 'data/fixity.json', 'data/fixity.js'] as $name) {
            $this->assertContains($name, $names, $name);
        }
        $this->assertNotContains('USER-MANUAL.txt', $names, 'only an editable package has the manual');
        $this->assertSame([], array_values(array_filter($names, fn ($n) => str_starts_with($n, 'assets/master/'))), 'never a master');

        $data = json_decode((string) $zip->getFromName('data/catalogue.json'), true);
        $this->assertSame(['Riverside Town Council records', 'Letter of 3 May 1902'], array_column($data['records'], 'title'));
        $letter = collect($data['records'])->firstWhere('id', $this->letter->id);
        $this->assertSame($this->fonds->id, $letter['parent']);
        $this->assertContains('A letter about the water supply.', array_column($letter['fields'], 1));
        $this->assertStringStartsWith('window.CATALOGUE = ', (string) $zip->getFromName('data/catalogue.js'), 'read as a script, so it opens from disk');
        $this->assertStringContainsString($this->letter->iri, (string) $zip->getFromName('data/catalogue.jsonld'));
        $this->assertStringNotContainsString('A draft letter', (string) $zip->getFromName('data/catalogue.jsonld'));
        $this->assertStringContainsString('<title>Riverside</title>', (string) $zip->getFromName('index.html'));

        // Every checksum is that of the file beside it.
        foreach (array_filter(explode("\n", (string) $zip->getFromName('SHA256SUMS'))) as $line) {
            [$sha, $path] = explode('  ', $line, 2);
            $this->assertSame($sha, hash('sha256', (string) $zip->getFromName($path)), $path);
        }
        $withheld = json_decode((string) $zip->getFromName('data/disclosure-summary.json'), true);
        $this->assertSame('the public', $withheld['read_as']);
        $this->assertSame(1, $withheld['withheld']['descriptions not readable as the package is read (unpublished, embargoed or restricted)']);
        $this->assertSame(1, $withheld['withheld']['masters not included (only an archive may carry them)']);
        $this->assertStringNotContainsString('===', (string) $zip->getFromName('README.txt'), 'no divider lines');
        $this->assertStringContainsString('Riverside Archives', (string) $zip->getFromName('README.txt'));

        $this->actingAs($this->editor)->get(route('offline-catalogues.download', $catalogue))->assertOk()->assertHeader('X-Content-SHA256', $catalogue->sha256);
        $this->actingAs($this->editor)->get(route('offline-catalogues.index'))->assertOk()->assertSee('Riverside')->assertSee('2 descriptions');
    }

    public function test_an_archive_holds_drafts_and_masters_for_its_maker_and_is_never_given_a_link(): void
    {
        $catalogue = $this->make($this->admin, ['mode' => 'archive', 'scope' => 'unit', 'record_resource_id' => $this->fonds->id, 'include_masters' => '1']);
        $this->assertSame('completed', $catalogue->status->notation, (string) $catalogue->error);
        $zip = $this->open($catalogue);
        $data = json_decode((string) $zip->getFromName('data/catalogue.json'), true);
        $this->assertContains('A draft letter', array_column($data['records'], 'title'));
        $this->assertNotSame([], array_values(array_filter($this->names($zip), fn ($n) => str_starts_with($n, 'assets/master/'))), 'its master');
        $this->actingAs($this->admin)->post(route('offline-catalogues.share', $catalogue))->assertForbidden();

        // PSIS imports a package back: its RiC-O is read by the RiC-O import (phase 11b), here a dry run in another institution.
        $other = Institution::create(['name' => 'Hill Archives', 'base_iri' => 'https://hill.example.org']);
        app(RoleService::class)->seedDefaults($other);
        $theirs = app(AccountService::class)->create($other, 'Theirs', 'admin@hill.example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($theirs, 'administrator');
        $location = $this->actingAs($theirs)->post('/imports', ['file' => UploadedFile::fake()->createWithContent('catalogue.jsonld', (string) $zip->getFromName('data/catalogue.jsonld')),
            'format' => 'ric-o', 'language' => 'en', 'repository' => app(RepositoryService::class)->create($other, 'Hill Archives', 'en', 'HA')->id])->assertRedirect()->assertSessionHasNoErrors()->headers->get('Location');
        $this->assertSame(4, Import::findOrFail(basename($location))->fresh()->report['actions']['created'], 'the repository, the fonds, the letter and the draft');
    }

    public function test_a_link_downloads_a_package_until_it_is_used_up_or_revoked(): void
    {
        $catalogue = $this->make($this->editor, ['mode' => 'editable']);
        $this->assertContains('USER-MANUAL.txt', $this->names($this->open($catalogue)));
        $link = $this->actingAs($this->editor)->post(route('offline-catalogues.share', $catalogue), ['max_downloads' => 1])->assertRedirect()->getSession()->get('link');
        $this->assertMatchesRegularExpression('~/offline-catalogues/shared/[A-Za-z0-9]{48}$~', $link);
        $this->assertStringNotContainsString(basename($link), (string) json_encode($catalogue->shares()->first()->getAttributes()), 'only the hash is kept');

        auth()->logout();
        $this->get($link)->assertOk()->assertHeader('X-Content-SHA256', $catalogue->sha256);
        $this->get($link)->assertStatus(410);

        $again = $this->actingAs($this->editor)->post(route('offline-catalogues.share', $catalogue))->getSession()->get('link');
        $share = $catalogue->shares()->whereNull('max_downloads')->firstOrFail();
        $this->actingAs($this->editor)->post(route('offline-catalogues.revoke', [$catalogue, $share]))->assertRedirect();
        auth()->logout();
        $this->get($again)->assertStatus(410);
        $this->get(route('offline-catalogues.shared', str_repeat('a', 48)))->assertNotFound();
    }

    public function test_what_an_editable_package_saves_comes_back_as_a_draft_submission(): void
    {
        // The file viewer.js writes: PSIS's researcher exchange file, format 1.0, each note naming its record by IRI.
        $exchange = ['format_version' => '1.0', 'source_config' => ['title' => 'Riverside', 'package_id' => 'x'], 'export_options' => ['include_images' => true], 'collections' => [
            ['type' => 'notes', 'items' => [['title' => 'Note on Letter of 3 May 1902 (L1)', 'note' => 'The signature is the town clerk\'s.', 'reference_iri' => $this->letter->iri],
                ['title' => 'Suggested Dates for Letter of 3 May 1902 (L1)', 'note' => '3 May 1902', 'reference_iri' => $this->letter->iri]]],
            ['type' => 'files', 'items' => [['title' => 'envelope.txt', 'notes' => 'Gathered offline for Letter of 3 May 1902, '.$this->letter->iri,
                'files' => [['filename' => 'envelope.txt', 'data' => 'data:text/plain;base64,'.base64_encode('An envelope.'), 'caption' => 'Gathered offline']]]]],
        ]];
        $result = app(Submissions::class)->importExchange($this->editor, UploadedFile::fake()->createWithContent('researcher-exchange.json', json_encode($exchange)));
        $this->assertSame(['notes' => 2, 'descriptions' => 1, 'creators' => 0, 'repositories' => 0, 'files' => 1], $result['counts']);
        $this->assertSame([$this->letter->id], $result['submission']->items()->whereNotNull('reference_record_id')->pluck('reference_record_id')->unique()->values()->all());
    }

    public function test_making_them_is_a_permission_and_another_institution_s_package_does_not_exist(): void
    {
        $catalogue = $this->make($this->editor, []);
        $contributor = app(AccountService::class)->create($this->institution, 'Contributor', 'c@riverside.example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($contributor, 'contributor');
        $this->actingAs($contributor)->get(route('offline-catalogues.index'))->assertForbidden();

        $other = Institution::create(['name' => 'Hill Archives', 'base_iri' => 'https://hill.example.org']);
        app(RoleService::class)->seedDefaults($other);
        $theirs = app(AccountService::class)->create($other, 'Theirs', 'admin@hill.example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($theirs, 'administrator');
        $this->actingAs($theirs)->get(route('offline-catalogues.download', $catalogue))->assertNotFound();
        $this->actingAs($theirs)->post(route('offline-catalogues.destroy', $catalogue))->assertNotFound();

        $this->actingAs($this->editor)->post(route('offline-catalogues.destroy', $catalogue))->assertRedirect();
        $this->assertNull(OfflineCatalogue::find($catalogue->id));
        $this->assertFalse(app(FileStore::class)->ocfl()->exists($catalogue->objectId()), 'purged from the store');
    }
}
