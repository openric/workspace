<?php

// OpenRiC Workspace - moving from AtoM: repositories, then authority records, then descriptions with their files (WS-015).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The rows are shaped as AtoM 2.10's own csv:repository-export,
// csv:authority-export and csv:export write them, with what a real export
// carries: NULL in the event lists, a control character pasted from camera
// metadata, a level AtoM has and ISAD(G) does not, units with no level, and
// files whose checksum AtoM recorded.

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\InstantiationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Exchange\DescriptionCsv;
use App\Exchange\DublinCore;
use App\Exchange\Ead;
use App\Exchange\ExportView;
use App\Exchange\Mods;
use App\Models\Agent;
use App\Models\Concept;
use App\Models\ContactPoint;
use App\Models\Import;
use App\Models\Institution;
use App\Models\Name;
use App\Models\Place;
use App\Models\RecordResource;
use App\Models\Relation;
use App\Models\Repository;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Relations\RelationService;
use App\Support\Vocab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Imagick;
use ImagickPixel;
use Tests\TestCase;

class AtomMigrationTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private User $editor;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/workspace-atom-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/uploads/r/riverside/a/b/c', 0777, true);
        config(['workspace.object_store' => $this->dir.'/objects', 'workspace.import_files' => $this->dir.'/uploads']);
        $this->loadBundledVocabularies();
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->editor = app(AccountService::class)->create($this->institution, 'Editor', 'editor@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->editor, 'editor');
        // The repository descriptions are imported into when their own is not here.
        app(RepositoryService::class)->create($this->institution, 'Workspace default', 'en');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function csv(array $rows): string
    {
        $out = fopen('php://memory', 'r+');
        foreach ($rows as $row) {
            fputcsv($out, $row, escape: '');
        }
        rewind($out);

        return stream_get_contents($out);
    }

    private function import(string $content, string $format, array $fields = []): Import
    {
        $location = $this->actingAs($this->editor)->post('/imports', [
            'file' => UploadedFile::fake()->createWithContent('export.csv', $content), 'format' => $format, 'source' => 'AtoM, Riverside, 2026',
            'language' => 'en', 'create_terms' => '1', ...$fields,
        ])->assertRedirect()->assertSessionHasNoErrors()->headers->get('Location');
        $import = Import::findOrFail(basename($location));
        $this->post("/imports/{$import->id}/commit")->assertRedirect(route('imports.show', $import));

        return $import->fresh();
    }

    private function issues(Import $import, ?string $kind = null): array
    {
        return array_values(array_filter($import->report['issues'], fn ($i) => $kind === null || $i['kind'] === $kind));
    }

    private function repositories(string $email = 'reading@riverside.example.org'): string
    {
        return $this->csv([
            ['legacyId', 'uploadLimit', 'identifier', 'authorizedFormOfName', 'parallelFormsOfName', 'otherFormsOfName', 'contactPerson', 'city', 'country', 'email', 'history', 'descriptionStatus', 'levelOfDetail', 'culture'],
            ['982', '-1', 'RVA', 'Riverside Valley Archives', '', 'RVA|Valley Archives', 'Reading room', 'Riverside', 'ZA', $email, 'Founded in 1931.', 'Final', 'Partial', 'en'],
        ]);
    }

    private function authorities(string $otherNames = 'Botha, J.|Jan Botha'): string
    {
        return $this->csv([
            ['legacyId', 'culture', 'typeOfEntity', 'authorizedFormOfName', 'otherFormsOfName', 'datesOfExistence', 'history', 'status', 'levelOfDetail',
                'actorOccupations', 'actorOccupationNotes', 'subjectAccessPoints', 'placeAccessPoints', 'digitalObjectURI', 'language'],
            ['747', 'en', 'Person', 'Botha, Jan', $otherNames, '1870-1931', 'Parish clerk of Riverside.', 'Final', 'Full', 'Parish clerk', 'Kept the registers', 'Marriages', 'Riverside', '', ''],
            ['747', 'af', 'Person', 'Botha, Jan', '', '', 'Gemeenteklerk van Riverside.', '', '', '', '', '', '', '', ''],
            ['830', 'en', '', 'Engelbrecht Family', '', '', '', '', '', '', '', '', '', '', ''],
            ['831', 'en', 'Corporate body', 'Riverside Parish Council', '', '', '', '', '', '', '', '', '', '', ''],
        ]);
    }

    private function descriptions(string $checksum): string
    {
        return $this->csv([
            ['legacyId', 'parentId', 'identifier', 'title', 'levelOfDescription', 'repository', 'physicalCharacteristics', 'eventTypes', 'eventDates',
                'eventStartDates', 'eventEndDates', 'eventActors', 'eventActorHistories', 'eventDescriptions', 'eventPlaces', 'digitalObjectURI', 'digitalObjectChecksum', 'slug', 'culture'],
            ['983', '', 'RPC', 'Riverside Parish Council records', 'Record group', 'Riverside Valley Archives', "Image size: 2339 x 1698 pixels\x1b[0m", 'Creation|Creation', '1902-1950|NULL',
                '1902-01-01|NULL', '1950-12-31|NULL', 'Riverside Parish Council|Botha, Jan', 'NULL|NULL', 'NULL|NULL', 'NULL|NULL',
                'https://atom.example.org/uploads/r/riverside/a/b/c/letter.png', $checksum, 'riverside-parish-council-records', 'en'],
            ['1058', '983', '1', 'Letter of 3 May 1902', '', 'Another Repository', '', '', '', '', '', '', '', '', '',
                'https://atom.example.org/uploads/r/riverside/a/b/c/letter.png', str_repeat('0', 64), 'letter', 'en'],
            ['1059', '983', '2', 'Register', '', '', '', '', '', '', '', '', '', '', '', 'https://atom.example.org/uploads/r/../../secret.png', '', 'register', 'en'],
        ]);
    }

    public function test_repositories_then_authority_records_then_descriptions_with_their_files_and_again_without_change(): void
    {
        // Repositories (ISDIAH): names, contact point, status and level of detail.
        $import = $this->import($this->repositories(), 'repository-csv');
        $this->assertTrue($import->report['reconciliation']['holds']);
        $repository = Repository::whereIn('id', Name::where(['subject_table' => 'repositories', 'value' => 'Riverside Valley Archives'])->select('subject_id'))->firstOrFail();
        $this->assertSame('RVA', $repository->identifier);
        $this->assertSame(['RVA', 'Valley Archives'], $repository->namesOfType('other')->pluck('value')->sort()->values()->all());
        $this->assertSame(['Reading room', 'Riverside', 'ZA', 'reading@riverside.example.org'],
            array_values($repository->contactPoints()->firstOrFail()->only(['contact_person', 'city', 'country_code', 'email'])));
        $this->assertSame(Vocab::DESCRIPTION_STATUSES.'final', $repository->fresh()->descriptionStatus->iri);
        $this->assertContains('uploadLimit', array_column($this->issues($import, 'left-out'), 'field'));

        // Authority records (ISAAR(CPF)): a translation row, an occupation and a place, a record with no type of entity.
        $import = $this->import($this->authorities(), 'authority-csv', ['agent_type' => 'family']);
        $this->assertEquals(['created' => 3, 'updated' => 0, 'unchanged' => 0, 'rejected' => 0], $import->report['actions']);
        $botha = Agent::whereIn('id', Name::where(['subject_table' => 'agents', 'value' => 'Botha, Jan'])->select('subject_id'))->firstOrFail();
        $this->get(route('imports.show', $import))->assertOk()->assertSee('Authority records: CSV')->assertSee(route('agents.show', $botha->id), false);
        $this->assertSame('person', $botha->agentType->notation);
        $this->assertSame('Gemeenteklerk van Riverside.', $botha->descriptionValues()->where('language', 'af')->value('value'));
        $this->assertSame(['Botha, J.', 'Jan Botha'], $botha->namesOfType('other')->pluck('value')->sort()->values()->all());
        $occupation = Relation::where(['source_table' => 'agents', 'source_id' => $botha->id])->whereHas('relationType', fn ($q) => $q->where('notation', 'occupation'))->firstOrFail();
        $this->assertSame('Kept the registers', $occupation->description);
        $this->assertSame('proposed', $occupation->standing());
        $this->assertTrue(Relation::where(['target_table' => 'agents', 'target_id' => $botha->id, 'source_table' => 'places'])->exists());
        $family = Agent::whereIn('id', Name::where(['subject_table' => 'agents', 'value' => 'Engelbrecht Family'])->select('subject_id'))->firstOrFail();
        $this->assertSame('family', $family->agentType->notation);
        $this->assertContains('no type of entity in the file; a family, as chosen for the import', array_column($this->issues($import, 'note'), 'problem'));
        $this->assertContains('subjectAccessPoints', array_column($this->issues($import, 'left-out'), 'field'));

        // Descriptions (ISAD(G)) with the file AtoM kept, from a copy of its uploads folder.
        $image = new Imagick;
        $image->newImage(400, 300, new ImagickPixel('#d8c9a3'));
        $image->setImageFormat('png');
        file_put_contents($this->dir.'/uploads/r/riverside/a/b/c/letter.png', $image->getImageBlob());
        file_put_contents($this->dir.'/secret.png', $image->getImageBlob());
        $checksum = hash_file('sha256', $this->dir.'/uploads/r/riverside/a/b/c/letter.png');
        $import = $this->import($this->descriptions($checksum), 'csv', ['repository' => Repository::whereIn('id', Name::where('value', 'Workspace default')->select('subject_id'))->value('id'), 'default_level' => 'item']);
        $this->assertEquals(['created' => 3, 'updated' => 0, 'unchanged' => 0, 'rejected' => 0], $import->report['actions']);
        $this->assertTrue($import->report['reconciliation']['holds']);
        $fonds = RecordResource::whereNull('parent_id')->firstOrFail();
        $this->assertSame('record-group', $fonds->level->notation);
        $this->assertStringContainsString('<archdesc level="recordgrp"', app(Ead::class)->export($fonds, new ExportView($this->editor, true)));
        $this->assertSame($repository->id, $fonds->repository_id, 'held by the repository its row names');
        $this->assertSame('Image size: 2339 x 1698 pixels[0m', $fonds->described('physical-characteristics', ['en']));
        $this->assertSame(['item', 'item'], RecordResource::whereNotNull('parent_id')->with('level')->get()->pluck('level.notation')->all());
        // AtoM's NULL is nothing: no agent called NULL, no date written NULL; the creators are the authority records imported before.
        $this->assertSame(0, Name::where('value', 'NULL')->count());
        $this->assertSame(3, Agent::count(), 'the creators are the three authority records, not new ones');
        $this->assertEqualsCanonicalizing(['creation', 'creation'], Relation::where(['source_table' => 'record_resources', 'source_id' => $fonds->id])
            ->with('relationType')->get()->pluck('relationType.notation')->all());
        $this->assertSame(['1902/1950'], $fonds->dates()->pluck('normalized_value')->all());
        $issues = collect($this->issues($import));
        $this->assertTrue($issues->contains(fn ($i) => $i['field'] === 'physicalCharacteristics' && str_contains($i['problem'], '1 control character removed')));
        $this->assertFalse($issues->contains(fn ($i) => in_array($i['field'], ['eventPlaces', 'eventDescriptions'], true)), 'a list of NULL holds nothing');
        $this->assertTrue($issues->contains(fn ($i) => $i['field'] === 'slug' && $i['kind'] === 'note'));
        // The file: kept when its checksum is AtoM's, refused when not, never read from outside the folder.
        $this->assertSame(1, $import->report['counts']['files']);
        $master = $fonds->instantiations()->firstOrFail()->file('master');
        $this->assertSame($checksum, $master->sha256);
        $this->assertNotNull($fonds->instantiations()->firstOrFail()->file('reference'));
        $this->assertTrue($issues->contains(fn ($i) => $i['location'] === 'row 3' && $i['field'] === 'digitalObjectChecksum' && $i['kind'] === 'left-out'));
        $this->assertTrue($issues->contains(fn ($i) => $i['location'] === 'row 4' && str_contains($i['problem'], 'not in the copy of AtoM\'s uploads folder')));
        $this->assertTrue($issues->contains(fn ($i) => $i['location'] === 'row 3' && $i['field'] === 'repository' && str_contains($i['problem'], 'holder of the unit at the top')));

        // The same files again: nothing changes, nothing is made twice, and a name is added, never put in place of one.
        $this->assertEquals(['created' => 0, 'updated' => 0, 'unchanged' => 1, 'rejected' => 0], $this->import($this->repositories(), 'repository-csv')->report['actions']);
        $again = $this->import($this->authorities('Jan Botha|J. Botha'), 'authority-csv', ['agent_type' => 'family']);
        $this->assertEquals(['created' => 0, 'updated' => 1, 'unchanged' => 2, 'rejected' => 0], $again->report['actions']);
        $this->assertSame(['Botha, J.', 'J. Botha', 'Jan Botha'], $botha->fresh()->namesOfType('other')->pluck('value')->sort()->values()->all());
        $this->assertEquals(['created' => 0, 'updated' => 0, 'unchanged' => 3, 'rejected' => 0],
            $this->import($this->descriptions($checksum), 'csv', ['repository' => $repository->id, 'default_level' => 'item'])->report['actions']);
        $this->assertSame(1, $fonds->instantiations()->count());
        $this->assertSame(1, ContactPoint::count());
    }

    public function test_an_authority_record_with_no_type_is_not_imported_unless_a_kind_is_chosen_and_a_unit_with_no_level_likewise(): void
    {
        $import = $this->import($this->authorities(), 'authority-csv');
        $this->assertSame(1, $import->report['actions']['rejected']);
        $this->assertStringContainsString('no type of entity', collect($import->report['units'])->firstWhere('key', '830')['message']);

        $holder = Repository::firstOrFail();
        $import = $this->import($this->csv([['legacyId', 'parentId', 'title', 'levelOfDescription', 'culture'], ['1', '', 'Fonds', 'Fonds', 'en'], ['2', '1', 'Loose page', '', 'en']]),
            'csv', ['repository' => $holder->id]);
        $this->assertEquals(['created' => 1, 'updated' => 0, 'unchanged' => 0, 'rejected' => 1], $import->report['actions']);
        $this->assertStringContainsString('choose a level for units with none', collect($import->report['units'])->firstWhere('key', '2')['message']);
    }

    public function test_genre_access_points_become_documentary_form_types_with_the_rico_term_of_the_unit_class_and_go_out_again(): void
    {
        $holder = Repository::firstOrFail();
        $import = $this->import($this->csv([['legacyId', 'parentId', 'title', 'levelOfDescription', 'genreAccessPoints', 'culture'],
            ['1', '', 'Riverside photographs', 'Fonds', 'Albums|Photographs', 'en'], ['2', '1', 'Wedding of 1902', 'Item', 'Photographs', 'en']]), 'csv', ['repository' => $holder->id]);
        $this->assertNotContains('genreAccessPoints', array_column($import->report['issues'], 'field'));
        $this->assertSame(2, $import->report['counts']['concepts']);
        [$fonds, $item] = [RecordResource::whereNull('parent_id')->firstOrFail(), RecordResource::whereNotNull('parent_id')->firstOrFail()];
        $forms = Relation::whereHas('relationType', fn ($q) => $q->where('notation', 'documentary-form'))->get();
        $this->assertCount(3, $forms);
        $this->assertTrue($forms->every(fn (Relation $r) => $r->standing() === 'proposed'));
        $this->assertSame(['documentary-form-type'], $forms->map(fn (Relation $r) => Concept::find($r->target_id)->vocabulary->kind->notation)->unique()->values()->all());

        // RiC-O 1.1 types a record by its documentary form, and a record set only through its members.
        $terms = array_flip(file(resource_path('ontologies/rico-1.1.terms'), FILE_IGNORE_NEW_LINES));
        $this->assertSame(['rico:hasOrHadSomeMembersWithDocumentaryFormType'], $forms->where('source_id', $fonds->id)->map->ricTerm()->unique()->values()->all());
        $this->assertSame(['rico:hasDocumentaryFormType'], $forms->where('source_id', $item->id)->map->ricTerm()->values()->all());
        $this->assertArrayHasKey('hasOrHadSomeMembersWithDocumentaryFormType', $terms);
        $this->assertArrayHasKey('hasDocumentaryFormType', $terms);
        $this->get(route('records.show', $fonds))->assertOk()->assertSee('Documentary form types (genre access points)')->assertSee('Albums')
            ->assertSee('rico:hasOrHadSomeMembersWithDocumentaryFormType');
        $this->get(route('records.show', $item))->assertOk()->assertSee('rico:hasDocumentaryFormType');

        // Once accepted, they leave in every format that has a place for them, and come back in.
        foreach ($forms as $relation) {
            app(RelationService::class)->review($relation->currentAssertions()->firstOrFail(), true, $this->editor);
        }
        $view = new ExportView($this->editor, true);
        $fonds = $fonds->fresh();
        $ead = app(Ead::class)->export($fonds, $view);
        $this->assertMatchesRegularExpression('#<genreform authfilenumber="[^"]+">Albums</genreform>#', $ead);
        $this->assertStringContainsString('Albums|Photographs', app(DescriptionCsv::class)->export([$fonds], $view));
        $this->assertMatchesRegularExpression('#<genre authority="local" type="documentary form" valueURI="[^"]+">Photographs</genre>#', app(Mods::class)->export($fonds, $view));
        $this->assertStringContainsString('<dc:type>Albums</dc:type>', app(DublinCore::class)->export($fonds, $view));
        $again = $this->import($ead, 'ead', ['repository' => $holder->id, 'source' => 'Workspace EAD']);
        $this->assertSame(0, $again->report['counts']['concepts'], 'the documentary form types are found by their IRI');
        $this->assertNotContains('controlaccess/genreform', array_column($again->report['issues'], 'field'));
    }

    public function test_a_file_image_magick_cannot_read_is_kept_without_copies(): void
    {
        $unit = app(RecordService::class)->create($this->institution, 'https://workspace.openric.org/vocab/level-of-description/item', 'Damaged scan', 'en',
            null, Repository::firstOrFail(), 'D1', $this->editor);
        $path = tempnam(sys_get_temp_dir(), 'bad');
        file_put_contents($path, "\x89PNG\r\n\x1a\n".random_bytes(2048));
        $instantiation = app(InstantiationService::class)->upload($unit, $path, 'damaged.png', 'en', null, null, 'online', $this->editor);
        $this->assertNotNull($instantiation->file('master'));
        $this->assertNull($instantiation->file('reference'));
        $this->assertNull($instantiation->file('thumbnail'));
    }

    public function test_only_those_who_may_create_the_kind_may_import_it(): void
    {
        $contributor = app(AccountService::class)->create($this->institution, 'Contributor', 'contributor@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($contributor, 'contributor');
        $this->actingAs($contributor)->get('/imports/new')->assertOk()->assertSee('Authority records: CSV')->assertDontSee('Repositories: CSV');
        $this->actingAs($contributor)->post('/imports', ['file' => UploadedFile::fake()->createWithContent('r.csv', $this->repositories()),
            'format' => 'repository-csv', 'language' => 'en'])->assertSessionHasErrors('format');
        $this->assertSame(0, Import::count());
        $this->assertSame(0, Place::count());
    }

    public function test_a_level_the_workspace_does_not_have_becomes_one_of_the_institutions_levels_with_the_class_its_units_take(): void
    {
        $holder = Repository::firstOrFail();
        $file = $this->csv([['legacyId', 'parentId', 'title', 'levelOfDescription', 'culture'],
            ['1', '', 'Herbarium', 'Fonds', 'en'], ['2', '1', 'Book of pressed flowers', 'Book', 'en'], ['3', '2', 'Page with a rose', 'Document', 'en'], ['4', '1', 'Loose sheet', 'document', 'en']]);
        $import = $this->import($file, 'csv', ['repository' => $holder->id]);
        $this->assertEquals(['created' => 4, 'updated' => 0, 'unchanged' => 0, 'rejected' => 0], $import->report['actions']);

        // One level per name, in the institution's level-of-description vocabulary; a level with units inside is a record set.
        $levels = Concept::bundledThenLocal('https://workspace.openric.org/vocab/level-of-description/', 'level-of-description', $this->institution->id)
            ->filter(fn (Concept $c) => $c->institution_id !== null);
        $this->assertEquals(['Book', 'Document'], $levels->map(fn (Concept $c) => $c->label(['en']))->sort()->values()->all());
        $book = RecordResource::whereHas('names', fn ($q) => $q->where('value', 'Book of pressed flowers'))->firstOrFail();
        $page = RecordResource::whereHas('names', fn ($q) => $q->where('value', 'Page with a rose'))->firstOrFail();
        $this->assertSame('rico:RecordSet', EntityKinds::ricClass($book));
        $this->assertSame('rico:Record', EntityKinds::ricClass($page));
        $this->assertStringContainsString('otherlevel="Book"', app(Ead::class)->export($book->parent, new ExportView($this->editor, true)));

        // The record forms offer it, and importing again changes nothing.
        $this->actingAs($this->editor)->get(route('records.edit', $book))->assertOk()->assertSee('>Book (rico:RecordSet)<', false);
        $again = $this->import($file, 'csv', ['repository' => $holder->id]);
        $this->assertEquals(['created' => 0, 'updated' => 0, 'unchanged' => 4, 'rejected' => 0], $again->report['actions']);
        $this->assertSame(2, $levels->count());

        // Without leave to make terms, a unit at a level the Workspace does not have is not imported.
        $refused = $this->import($this->csv([['legacyId', 'parentId', 'title', 'levelOfDescription', 'culture'], ['9', '', 'Atlas', 'Monograph', 'en']]),
            'csv', ['repository' => $holder->id, 'create_terms' => '0']);
        $this->assertSame(1, $refused->report['actions']['rejected']);
        $this->assertStringContainsString('"Monograph" is not a level of description', $refused->report['units'][0]['message']);
    }
}
