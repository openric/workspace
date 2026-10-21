<?php

// OpenRiC Workspace - instantiations and their files: upload, fixity, controlled delivery (WS-007; the V0.1 demonstration step 8).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\InstantiationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\DigitalFile;
use App\Models\Instantiation;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Imagick;
use ImagickPixel;
use Tests\TestCase;

class InstantiationTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private string $store;

    private Institution $institution;

    private RecordResource $item;

    private User $editor;

    private User $contributor;

    private User $translator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        $this->loadBundledVocabularies();
        $accounts = app(AccountService::class);
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        foreach (['editor', 'contributor', 'translator'] as $role) {
            $this->{$role} = $accounts->create($this->institution, ucfirst($role), "$role@example.org", 'correct horse battery staple');
            $accounts->grantTemplateRole($this->{$role}, $role);
        }
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $this->item = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Minute book, 1902', 'en', null, $holder, 'MB1');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->store)) {
            exec('rm -rf '.escapeshellarg($this->store));
        }
        parent::tearDown();
    }

    /** A PNG scan, named as something else, so the type must come from the content. */
    private function scan(int $width = 2400, int $height = 1800): UploadedFile
    {
        $image = new Imagick;
        $image->newImage($width, $height, new ImagickPixel('#d8c9a3'));
        $image->setImageFormat('png');

        return UploadedFile::fake()->createWithContent('minute-book-scan.dat', $image->getImageBlob());
    }

    private function upload(UploadedFile $file, array $fields = []): TestResponse
    {
        return $this->actingAs($this->editor)->post("/records/{$this->item->id}/instantiations", ['file' => $file, 'language' => 'en', 'availability' => 'online', ...$fields]);
    }

    public function test_an_upload_is_checked_kept_by_its_checksum_and_copied_for_viewing(): void
    {
        $file = $this->scan();
        $sha = hash('sha256', $file->getContent());
        $this->upload($file, ['sha256' => strtoupper($sha), 'title' => 'Scan of the minute book'])->assertRedirect()->assertSessionHas('status', "File kept, SHA-256 $sha.");
        $instantiation = Instantiation::with('files.use')->sole();
        $this->assertSame(['digital-file', 'online', 'Scan of the minute book'], [$instantiation->carrierType->notation, $instantiation->availability->notation, $instantiation->described('instantiation-title', ['en'])]);
        $this->assertStringStartsWith('https://archives.example.org/instantiation/', $instantiation->iri);
        $master = $instantiation->file('master');
        $this->assertSame([$sha, 'image/png', 2400, 1800, 'minute-book-scan.dat'], [$master->sha256, $master->mime_type, $master->width, $master->height, $master->original_name]);
        $this->assertFileExists($this->store.'/'.$this->institution->id.'/'.substr($sha, 0, 2).'/'.substr($sha, 2, 2).'/'.$sha);
        $this->assertStringNotContainsString(public_path(), app(InstantiationService::class)->path($master), 'outside the web root');
        $this->assertSame([1600, 1200, 'image/jpeg'], [$instantiation->file('reference')->width, $instantiation->file('reference')->height, $instantiation->file('reference')->mime_type]);
        $this->assertSame(300, $instantiation->file('thumbnail')->width);
        $this->assertNull($master->scanned_with, 'no scanner configured in the tests');

        $this->actingAs($this->editor)->get("/records/{$this->item->id}")->assertOk()->assertSee('Scan of the minute book')
            ->assertSee(route('files.show', $instantiation->file('thumbnail')), false);
        $this->actingAs($this->editor)->get("/instantiations/{$instantiation->id}")->assertOk()->assertSee($sha)->assertSee('Reference copy')
            ->assertSee('Not scanned for viruses')->assertSee('openricx:hasChecksum');
        $this->actingAs($this->editor)->get($instantiation->iri)->assertRedirect(route('instantiations.show', $instantiation));
    }

    public function test_a_file_whose_checksum_does_not_match_is_refused_and_nothing_is_kept(): void
    {
        $this->upload($this->scan(), ['sha256' => str_repeat('a', 64)])->assertSessionHasErrors('file');
        $this->assertSame(0, Instantiation::count());
        $this->assertDirectoryDoesNotExist($this->store);
        $this->upload($this->scan(), ['sha256' => 'not-a-checksum'])->assertSessionHasErrors('file');
    }

    public function test_a_pdf_gets_copies_of_its_first_page_and_other_files_none(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
        $this->upload(UploadedFile::fake()->createWithContent('minutes.pdf', $pdf))->assertRedirect();
        $instantiation = Instantiation::with('files.use')->sole();
        $this->assertSame('application/pdf', $instantiation->file('master')->mime_type);
        $this->assertSame('image/jpeg', $instantiation->file('thumbnail')?->mime_type, 'pdftoppm makes the first page an image');
        $this->upload(UploadedFile::fake()->createWithContent('notes.txt', "Notes on the minute book.\n"))->assertRedirect();
        $this->assertSame(['master'], Instantiation::latest('position')->first()->files->map->use->pluck('notation')->all());
    }

    /** The smallest glTF binary: the 12-byte header and a JSON chunk naming the glTF version. */
    private function model(): UploadedFile
    {
        $json = str_pad('{"asset":{"version":"2.0"}}', 28);

        return UploadedFile::fake()->createWithContent('helmet.glb', 'glTF'.pack('VV', 2, 12 + 8 + strlen($json)).pack('V', strlen($json)).'JSON'.$json);
    }

    public function test_a_glb_model_is_its_own_reference_copy_and_is_shown_in_the_3d_viewer(): void
    {
        $this->upload($this->model())->assertRedirect();
        $instantiation = Instantiation::with('files.use')->sole();
        [$master, $reference] = [$instantiation->file('master'), $instantiation->file('reference')];
        $this->assertSame('model/gltf-binary', $master->mime_type);
        $this->assertSame([$master->sha256, $master->storage_path, 'model/gltf-binary', 'helmet-reference.glb'],
            [$reference->sha256, $reference->storage_path, $reference->mime_type, $reference->original_name], 'the same bytes, kept once');
        $this->assertNull($instantiation->file('thumbnail'), 'a model is not drawn on the server');

        // Seen by one who may read reference copies but not masters, through the viewer the Workspace serves itself.
        $this->actingAs($this->contributor)->get(route('files.show', $reference))->assertOk()->assertHeader('Content-Type', 'model/gltf-binary');
        $page = $this->actingAs($this->contributor)->get("/records/{$this->item->id}")->assertOk()
            ->assertSee('<model-viewer src="'.route('files.show', $reference).'"', false)->assertSee('vendor/model-viewer/model-viewer.min.js')
            ->assertSee('dracoDecoderLocation')->assertDontSee('gstatic.com');
        $this->assertSame(1, substr_count($page->getContent(), 'model-viewer.min.js'), 'the viewer loads once');
        $this->actingAs($this->contributor)->get("/instantiations/{$instantiation->id}")->assertOk()->assertSee('<model-viewer', false);
        foreach (['model-viewer.min.js', 'draco/draco_decoder.wasm', 'draco/draco_wasm_wrapper.js', 'basis/basis_transcoder.wasm', 'README.md'] as $vendored) {
            $this->assertFileExists(public_path("vendor/model-viewer/$vendored"));
        }
    }

    public function test_the_copies_command_makes_copies_a_master_kept_without_them_has_and_only_once(): void
    {
        $this->upload($this->model())->assertRedirect();
        $instantiation = Instantiation::with('files.use')->sole();
        // As a model was kept before 0.0.34: its master only.
        DB::table('digital_files')->where('id', $instantiation->file('reference')->id)->delete();
        $this->upload($this->scan(400, 300))->assertRedirect();

        $this->artisan('workspace:copies')->expectsOutputToContain('1 copy made of helmet.glb')->expectsOutputToContain('2 instantiations with files looked at, 1 copies made.')->assertSuccessful();
        $this->assertSame('model/gltf-binary', $instantiation->fresh()->load('files.use')->file('reference')?->mime_type);
        $this->artisan('workspace:copies')->expectsOutputToContain('0 copies made.')->assertSuccessful();
        $this->assertSame(1, DigitalFile::where('instantiation_id', $instantiation->id)->where('mime_type', 'model/gltf-binary')->where('original_name', 'helmet-reference.glb')->count());
    }

    public function test_delivery_needs_the_read_permission_of_the_use_and_follows_availability(): void
    {
        $this->upload($this->scan(400, 300))->assertRedirect();
        $instantiation = Instantiation::with('files.use')->sole();
        [$master, $reference] = [$instantiation->file('master'), $instantiation->file('reference')];

        $response = $this->actingAs($this->editor)->get(route('files.show', $master))->assertOk()->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Digest', 'sha-256='.base64_encode(hex2bin($master->sha256)));
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertSame($master->sha256, hash_file('sha256', $response->getFile()->getPathname()));
        $this->assertStringStartsWith('attachment', $this->actingAs($this->editor)->get(route('files.show', [$master, 'download' => 1]))->headers->get('Content-Disposition'));

        $this->actingAs($this->contributor)->get(route('files.show', $master))->assertNotFound();
        $this->actingAs($this->contributor)->get(route('files.show', $reference))->assertOk();
        $this->actingAs($this->contributor)->get("/instantiations/{$instantiation->id}")->assertOk()->assertSee('needs the read-master permission');

        app(InstantiationService::class)->updateFacts($instantiation, 'digital-file', 'on-request');
        $this->actingAs($this->translator)->get(route('files.show', $reference))->assertNotFound();
        // On request: delivered only to those who may update the unit.
        $this->actingAs($this->contributor)->get(route('files.show', $reference))->assertOk();
        app(InstantiationService::class)->updateFacts($instantiation, 'digital-file', 'withdrawn');
        $this->actingAs($this->editor)->get(route('files.show', $master))->assertNotFound();

        $other = Institution::create(['name' => 'Elsewhere', 'base_iri' => 'https://elsewhere.example.org']);
        app(RoleService::class)->seedDefaults($other);
        $stranger = app(AccountService::class)->create($other, 'Stranger', 'stranger@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($stranger, 'administrator');
        app(InstantiationService::class)->updateFacts($instantiation, 'digital-file', 'online');
        $this->actingAs($stranger)->get(route('files.show', $master))->assertNotFound();
        $this->actingAs($stranger)->get("/instantiations/{$instantiation->id}")->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->get(route('files.show', $master))->assertRedirect(route('sign-in'));
    }

    public function test_the_fixity_check_finds_a_changed_or_missing_file(): void
    {
        $this->upload($this->scan(200, 100))->assertRedirect();
        $master = DigitalFile::whereHas('use', fn ($q) => $q->where('notation', 'master'))->sole();
        $audited = DB::table('audit_log')->count();
        $this->assertSame(0, Artisan::call('workspace:fixity'));
        $this->assertSame($audited, DB::table('audit_log')->count(), 'a passing check is not audited');

        file_put_contents(app(InstantiationService::class)->path($master), 'changed on disk');
        $this->assertSame(1, Artisan::call('workspace:fixity'));
        $this->assertStringContainsString('1 failed', Artisan::output());
        $this->assertNotNull($master->fresh()->fixity_failed_at);
        $this->assertTrue(DB::table('audit_log')->where(['subject_table' => 'digital_files', 'subject_id' => $master->id])->whereNotNull('after->fixity_failed_at')->exists());
        $this->actingAs($this->editor)->get("/instantiations/{$master->instantiation_id}")->assertSee('Fixity check failed');
        $this->actingAs($this->editor)->post(route('files.fixity', $master))->assertSessionHas('status', fn ($s) => str_starts_with($s, 'Fixity check failed'));
    }

    public function test_a_carrier_without_a_file_and_the_rules_the_database_keeps(): void
    {
        $this->actingAs($this->editor)->post("/records/{$this->item->id}/instantiations", ['language' => 'en', 'availability' => 'online'])->assertSessionHasErrors('title');
        $this->actingAs($this->editor)->post("/records/{$this->item->id}/instantiations", ['title' => 'The bound volume', 'language' => 'en', 'carrier_type' => 'paper', 'availability' => 'online'])->assertRedirect();
        $paper = Instantiation::sole();
        $this->actingAs($this->editor)->post("/instantiations/{$paper->id}", ['language' => 'en', 'carrier_type' => 'paper', 'availability' => 'online',
            'values' => ['instantiation-physical-characteristics' => 'Water damage to the last twenty leaves.']])->assertRedirect();
        $this->actingAs($this->editor)->get("/instantiations/{$paper->id}")->assertOk()->assertSee('Water damage')->assertSee('rico:physicalCharacteristicsNote')
            ->assertSee('No file: a carrier recorded without one');
        $this->actingAs($this->contributor)->post("/records/{$this->item->id}/instantiations", ['title' => 'x', 'language' => 'en', 'availability' => 'online'])->assertRedirect();
        $this->actingAs($this->translator)->post("/records/{$this->item->id}/instantiations", ['title' => 'x', 'language' => 'en', 'availability' => 'online'])->assertForbidden();
        $this->actingAs($this->editor)->get("/instantiations/{$paper->id}/history")->assertOk()->assertSee('Water damage');

        // Deleting the unit waits for its instantiations.
        $this->actingAs($this->editor)->get("/records/{$this->item->id}/delete")->assertSee('It has 2 instantiations; delete them first.');
        $other = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Another', 'en', null, $this->item->repository, 'X');
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('instantiations')->where('id', $paper->id)->update(['record_resource_id' => $other->id])), QueryException::class);

        $this->upload($this->scan(50, 50))->assertRedirect();
        $master = DigitalFile::whereHas('use', fn ($q) => $q->where('notation', 'master'))->sole();
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('digital_files')->where('id', $master->id)->update(['sha256' => str_repeat('0', 64)])), QueryException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('instantiations')->where('id', $paper->id)->delete()), QueryException::class);
    }

    public function test_a_virus_is_refused_where_the_scanner_runs(): void
    {
        if (! is_executable('/usr/bin/clamdscan') || ! file_exists('/var/run/clamav/clamd.ctl')) {
            $this->markTestSkipped('no ClamAV daemon here');
        }
        config(['workspace.virus_scanner' => 'clamdscan']);
        // The EICAR test file: harmless, and found by every scanner by design.
        $eicar = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
        $this->upload(UploadedFile::fake()->createWithContent('eicar.com', $eicar))->assertSessionHasErrors('file');
        $this->assertSame('The virus scanner found Eicar-Test-Signature, so the file was not kept.', session('errors')->first('file'));
        $this->assertSame(0, Instantiation::count());
        $this->upload($this->scan(30, 30))->assertRedirect();
        $this->assertSame('clamdscan', DigitalFile::whereHas('use', fn ($q) => $q->where('notation', 'master'))->sole()->scanned_with);
    }
}
