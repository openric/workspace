<?php

// OpenRiC Workspace - the import's ingest steps (roadmap phase 22a): a spreadsheet in a ZIP with the files its rows
// name, its headings mapped and the mapping kept, what it creates classified, the import as a BagIt bag, undoing it
// without losing later work, and rows entered in a grid.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Import;
use App\Models\ImportMapping;
use App\Models\Instantiation;
use App\Models\Institution;
use App\Models\RecordClassification;
use App\Models\RecordResource;
use App\Models\User;
use App\Rights\Clearances;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Imagick;
use ImagickPixel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;
use ZipArchive;

class IngestTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private string $store;

    private Institution $institution;

    /** @var array<string, User> */
    private array $people = [];

    private RecordResource $fonds;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        $this->travelTo('2026-11-05 09:00');
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        foreach (['officer' => 'administrator', 'deputy' => 'administrator', 'editor' => 'editor'] as $who => $template) {
            $this->people[$who] = app(AccountService::class)->create($this->institution, ucfirst($who), "$who@example.org", 'correct horse battery staple');
            app(AccountService::class)->grantTemplateRole($this->people[$who], $template);
        }
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $this->fonds = app(RecordService::class)->create($this->institution, self::LEVEL.'fonds', 'Photographs of the river', 'en', null, $holder, 'PR');
        app(PublicationService::class)->apply($this->fonds, 'publish', null, false, $this->people['officer']);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->store)) {
            exec('rm -rf '.escapeshellarg($this->store));
        }
        parent::tearDown();
    }

    /** A ZIP of a spreadsheet in another system's headings and the image one of its rows names. */
    private function bundle(): string
    {
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->fromArray([['Ref', 'Title', 'Date', 'Description', 'File', 'Box'],
            ['P-1', 'The weir in flood', '1952', 'Taken from the bridge.', 'images/weir.png', '3'],
            ['P-2', 'The ferry', '1953', '', '', '3']]);
        $xlsx = tempnam(sys_get_temp_dir(), 'sheet').'.xlsx';
        (new Xlsx($sheet))->save($xlsx);
        $image = new Imagick;
        $image->newImage(40, 30, new ImagickPixel('#88aacc'));
        $image->setImageFormat('png');
        $zipPath = tempnam(sys_get_temp_dir(), 'bundle').'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFile($xlsx, 'photographs.xlsx');
        $zip->addFromString('images/weir.png', $image->getImageBlob());
        $zip->close();

        return $zipPath;
    }

    public function test_a_spreadsheet_in_a_zip_is_mapped_classified_bagged_and_undone(): void
    {
        $officer = $this->people['officer'];
        $levels = app(Clearances::class)->levels($this->institution->id);
        app(Clearances::class)->grant($this->people['deputy'], $officer, $levels->firstWhere('code', 'CONFIDENTIAL'), null, null);
        app(Clearances::class)::flush();

        // Its headings are not AtoM's, so they are mapped first, each offered the column it most likely is.
        $location = $this->actingAs($officer)->post(route('imports.store'), ['file' => UploadedFile::fake()->createWithContent('photographs.zip', file_get_contents($this->bundle())),
            'format' => 'csv', 'repository' => $this->fonds->repository_id, 'parent' => $this->fonds->id, 'language' => 'en', 'default_level' => 'item',
            'classification_level' => $levels->firstWhere('code', 'CONFIDENTIAL')->id])->assertRedirect()->headers->get('Location');
        $import = Import::sole();
        $this->assertSame(route('imports.mapping', $import), $location);
        $this->assertNotNull($import->options['bundle']);
        $page = $this->actingAs($officer)->get(route('imports.mapping', $import))->assertOk();
        foreach (['identifier', 'title', 'eventDates', 'scopeAndContent', 'digitalObjectPath'] as $suggested) {
            $page->assertSee('value="'.$suggested.'" selected', false);
        }
        $this->actingAs($officer)->post(route('imports.commit', $import))->assertRedirect(route('imports.mapping', $import));

        // Mapped, with the box left out and a default level; the mapping is kept by name.
        $this->actingAs($officer)->post(route('imports.mapping.save', $import), ['keep_as' => 'River photographs', 'columns' => [
            ['target' => 'identifier'], ['target' => 'title'], ['target' => 'eventDates'], ['target' => 'scopeAndContent'], ['target' => 'digitalObjectPath'], ['target' => ''],
        ]])->assertRedirect(route('imports.show', $import));
        $this->assertSame('identifier', ImportMapping::sole()->columns['Ref']['target']);
        $import->refresh();
        $this->assertEquals(['created' => 2, 'updated' => 0, 'unchanged' => 0, 'rejected' => 0], $import->report['actions']);
        $this->assertContains('found in the ZIP; kept as a digital instantiation when imported', array_column($import->report['issues'], 'problem'));

        // Committed: the image kept as the first photograph's instantiation, both classified.
        $this->actingAs($officer)->post(route('imports.commit', $import))->assertRedirect();
        $made = RecordResource::where('parent_id', $this->fonds->id)->get();
        $this->assertCount(2, $made);
        $weir = $made->first(fn ($r) => $r->identifier === 'P-1');
        $this->assertSame('weir.png', Instantiation::where('record_resource_id', $weir->id)->sole()->files()->whereHas('use', fn ($q) => $q->where('notation', 'master'))->first()->original_name);
        $this->assertSame(2, RecordClassification::whereIn('record_resource_id', $made->pluck('id'))->count());

        // The import as a BagIt bag: what came in, the file it kept, what became of each entry, every SHA-256.
        $response = $this->actingAs($officer)->get(route('imports.bag', $import))->assertOk();
        $zip = new ZipArchive;
        $zip->open($response->getFile()->getPathname());
        $bag = 'import-'.substr($import->id, 0, 8);
        $this->assertSame("BagIt-Version: 1.0\nTag-File-Character-Encoding: UTF-8\n", $zip->getFromName("$bag/bagit.txt"));
        $manifest = $zip->getFromName("$bag/manifest-sha256.txt");
        $this->assertStringContainsString('data/source/photographs.zip', $manifest);
        $this->assertStringContainsString('data/files/RA_PR_P-1/weir.png', $manifest);
        $this->assertStringContainsString($weir->iri, $zip->getFromName("$bag/data/entries.csv"));
        foreach (explode("\n", trim($manifest)) as $line) {
            [$sha, $file] = explode('  ', $line);
            $this->assertSame($sha, hash('sha256', $zip->getFromName("$bag/$file")), $file);
        }
        $zip->close();

        // Undone: the photograph changed since is kept; the other goes, its IRI kept as deprecated.
        app(RecordService::class)->describe($weir, ['scope-and-content' => 'Taken from the bridge, looking upstream.'], 'en');
        $this->actingAs($officer)->post(route('imports.undo', $import))->assertSessionHasNoErrors();
        $this->assertNull($weir->fresh()->deleted_at);
        $this->assertNotNull($made->first(fn ($r) => $r->identifier === 'P-2')->fresh()->deleted_at);
        $this->assertSame([1, 'changed since it was imported'], [$import->fresh()->options['undo']['deleted'], $import->fresh()->options['undo']['kept'][0]['why']]);
        $this->actingAs($officer)->post(route('imports.undo', $import))->assertSessionHasErrors('import');
    }

    public function test_rows_entered_in_a_grid_go_through_the_dry_run(): void
    {
        $editor = $this->people['editor'];
        $this->actingAs($editor)->get(route('imports.grid', ['parent' => $this->fonds->id]))->assertOk()->assertSee(__('Validate and save'));
        $this->actingAs($editor)->post(route('imports.grid.save'), ['parent' => $this->fonds->id, 'language' => 'en', 'rows' => [['title' => '']]])->assertSessionHasErrors('grid');
        $this->actingAs($editor)->post(route('imports.grid.save'), ['parent' => $this->fonds->id, 'language' => 'en', 'rows' => [
            ['title' => 'Mill on the river', 'identifier' => 'G-1', 'levelOfDescription' => 'item', 'eventDates' => '1960', 'subjectAccessPoints' => 'Mills|Rivers'],
            ['title' => 'Lock gates', 'identifier' => 'G-2', 'levelOfDescription' => 'file'],
            ['title' => '', 'identifier' => ''],
        ]])->assertRedirect();
        $import = Import::sole();
        $this->assertSame($this->fonds->id, $import->parent_id);
        $this->assertEquals(['created' => 2, 'updated' => 0, 'unchanged' => 0, 'rejected' => 0], $import->report['actions']);
        $this->assertSame(0, RecordResource::where('parent_id', $this->fonds->id)->count(), 'nothing written before the import');
        $this->actingAs($editor)->post(route('imports.commit', $import))->assertRedirect();
        $this->assertEqualsCanonicalizing(['G-1', 'G-2'], RecordResource::where('parent_id', $this->fonds->id)->get()->map(fn ($r) => $r->identifier)->all());
        // Another institution's account has no grid under this record set.
        $other = Institution::create(['name' => 'Elsewhere', 'base_iri' => 'https://elsewhere.example.org']);
        app(RoleService::class)->seedDefaults($other);
        $stranger = app(AccountService::class)->create($other, 'Stranger', 'stranger@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($stranger, 'editor');
        $this->actingAs($stranger)->get(route('imports.grid', ['parent' => $this->fonds->id]))->assertNotFound();
    }
}
