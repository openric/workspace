<?php

// OpenRiC Workspace - import of units of description from CSV and EAD: dry run, report, import, repeat (WS-015).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\AgentService;
use App\Description\DateService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Agent;
use App\Models\Concept;
use App\Models\Import;
use App\Models\Institution;
use App\Models\Place;
use App\Models\RecordResource;
use App\Models\Relation;
use App\Models\Repository;
use App\Models\User;
use App\Relations\RelationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private Institution $institution;

    private Repository $holder;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->editor = app(AccountService::class)->create($this->institution, 'Editor', 'editor@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->editor, 'editor');
        $this->holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
    }

    private function upload(string $content, string $format, array $fields = [], string $name = 'file'): Import
    {
        $location = $this->actingAs($this->editor)->post('/imports', [
            'file' => UploadedFile::fake()->createWithContent($name.($format === 'csv' ? '.csv' : '.xml'), $content),
            'format' => $format, 'repository' => $this->holder->id, 'language' => 'en', ...$fields,
        ])->assertRedirect()->assertSessionHasNoErrors()->headers->get('Location');

        return Import::findOrFail(basename($location));
    }

    private function commit(Import $import): Import
    {
        $this->actingAs($this->editor)->post("/imports/{$import->id}/commit")->assertRedirect(route('imports.show', $import));

        return $import->fresh();
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

    private const HEADER = ['legacyId', 'parentId', 'identifier', 'title', 'levelOfDescription', 'scopeAndContent', 'extentAndMedium', 'repository',
        'subjectAccessPoints', 'placeAccessPoints', 'nameAccessPoints', 'eventTypes', 'eventDates', 'eventStartDates', 'eventEndDates', 'eventActors',
        'eventActorHistories', 'digitalObjectPath', 'culture', 'myOwnColumn'];

    private function atomFile(string $scope = 'Minutes and correspondence.'): string
    {
        return $this->csv([self::HEADER,
            ['1', '', 'RPC', 'Riverside Parish Council records', 'Fonds', $scope, '3 boxes', 'Riverside Archives', 'Local government|Elections', 'Riverside',
                'Engelbrecht, Johanna', 'Creation', '1902 to 1950', '1902-01-01', '1950-12-31', 'Riverside Parish Council', 'Established in 1902.', 'files/a.tif', 'en', 'x'],
            ['1', '', '', 'Rekords van die Riverside-gemeenteraad', '', 'Notules en korrespondensie.', '', '', '', '', '', '', '', '', '', '', '', '', 'af', ''],
            ['2', '1', '1', 'Minutes', 'Series', '', '', '', '', '', '', '', '', '', '', '', '', '', 'en', ''],
            ['3', '2', '3', 'Minutes, 1902', 'Bundle', '', '', '', '', '', '', '', '', '', '', '', '', '', 'en', ''],
            ['4', '3', '1', 'Inside the bundle', 'Item', '', '', '', '', '', '', '', '', '', '', '', '', '', 'en', ''],
        ]);
    }

    public function test_a_csv_dry_run_writes_nothing_and_reports_every_field_it_cannot_take(): void
    {
        $import = $this->upload($this->atomFile(), 'csv', ['agent_type' => 'corporate-body', 'create_terms' => '1', 'source' => 'AtoM Riverside']);
        $this->assertSame(0, RecordResource::count(), 'a dry run writes nothing');
        $this->assertSame(0, Agent::count());
        $this->assertNull($import->committed_at);
        $this->assertEquals(['created' => 4, 'updated' => 0, 'unchanged' => 0, 'rejected' => 0], $import->report['actions']);
        $issues = collect($import->report['issues']);
        foreach (['myOwnColumn', 'digitalObjectPath', 'publicationStatus'] as $field) {
            $this->assertTrue($issues->contains('field', $field), "$field is reported");
        }
        $units = collect($import->report['units']);
        // A level the Workspace does not have becomes one of the institution's, as the import may make terms.
        $this->assertTrue($issues->contains(fn ($i) => $i['field'] === 'level' && $i['value'] === 'Bundle' && $i['kind'] === 'created'));
        $this->assertSame('created', $units->firstWhere('key', '4')['action']);
        $this->actingAs($this->editor)->get("/imports/{$import->id}")->assertOk()->assertSee('Dry run: nothing has been written.')
            ->assertSee('would be created')->assertSee('Import now')->assertSee('not a column of AtoM');
    }

    public function test_a_csv_is_imported_with_translations_events_creators_and_access_points_and_imports_again_without_duplicates(): void
    {
        $import = $this->commit($this->upload($this->atomFile(), 'csv', ['agent_type' => 'corporate-body', 'create_terms' => '1', 'source' => 'AtoM Riverside']));
        $this->assertNotNull($import->committed_at);
        $this->assertTrue($import->report['reconciliation']['holds']);
        $fonds = RecordResource::whereNull('parent_id')->sole();
        $this->assertSame(['RPC', 'Riverside Parish Council records', 'Rekords van die Riverside-gemeenteraad', 'Notules en korrespondensie.', '3 boxes'],
            [$fonds->identifier, $fonds->described('title', ['en']), $fonds->described('title', ['af']), $fonds->described('scope-and-content', ['af']), $fonds->described('extent', ['en'])]);
        $this->assertSame('1902/1950', $fonds->dates()->sole()->normalized_value);
        $this->assertSame('1902 to 1950', $fonds->dates()->sole()->expressed);
        $council = Agent::whereHas('names', fn ($q) => $q->where('value', 'Riverside Parish Council'))->sole();
        $this->assertSame('corporate-body', $council->typeNotation());
        $this->assertSame('Established in 1902.', $council->described('agent-history', ['en']));
        $relations = app(RelationService::class)->of($fonds);
        $this->assertSame(['creation', 'place', 'subject', 'subject', 'subject'], $relations->map(fn ($r) => $r->relationType->notation)->sort()->values()->all());
        $this->assertTrue($relations->every(fn (Relation $r) => $r->standing() === 'proposed'), 'an import proposes its relations');
        $this->assertSame(1, Place::count());
        $this->assertSame('Minutes', RecordResource::where('parent_id', $fonds->id)->sole()->described('title', ['en']));

        // The same source again: nothing new; a changed value updates its unit only.
        $again = $this->commit($this->upload($this->atomFile(), 'csv', ['agent_type' => 'corporate-body', 'create_terms' => '1', 'source' => 'AtoM Riverside']));
        $this->assertEquals(['created' => 0, 'updated' => 0, 'unchanged' => 4, 'rejected' => 0], $again->report['actions']);
        $changed = $this->commit($this->upload($this->atomFile('Minutes, correspondence and accounts.'), 'csv', ['source' => 'AtoM Riverside']));
        $this->assertEquals(['created' => 0, 'updated' => 1, 'unchanged' => 3, 'rejected' => 0], $changed->report['actions']);
        $this->assertSame([4, 2, 5], [RecordResource::count(), Agent::count(), Relation::count()], 'the council and the name access point, created as the import was asked to');
        $this->assertSame('Minutes, correspondence and accounts.', $fonds->fresh()->described('scope-and-content', ['en']));
        $this->assertSame('draft', $fonds->fresh()->descriptionValues->first(fn ($v) => $v->value === 'Minutes, correspondence and accounts.')->state->notation);
    }

    public function test_a_finding_aid_the_workspace_exported_reads_back_unchanged_and_matches_by_iri(): void
    {
        $records = app(RecordService::class);
        $fonds = $records->create($this->institution, self::LEVEL.'fonds', 'Council records', 'en', null, $this->holder, 'C');
        $series = $records->create($this->institution, self::LEVEL.'series', 'Minutes', 'en', $fonds, null, '1');
        $part = $records->create($this->institution, self::LEVEL.'part', 'A part', 'en', $series, null, '2');
        $records->describe($fonds, ['scope-and-content' => "One.\n\nTwo.", 'rules-or-conventions' => 'ISAD(G)', 'archivists-note' => 'Described by A.'], 'en');
        app(DateService::class)->add($fonds, ['property' => 'creation', 'normalized' => '1900/1950-06', 'expressed' => 'c. 1900-1950', 'language' => 'en']);
        // Found in the rehearsal: a date with no written form is exported with its EDTF as text, which is not a new written form.
        app(DateService::class)->add($series, ['property' => 'creation', 'normalized' => '1902/1950-06']);
        $agent = app(AgentService::class)->create($this->institution, 'person', 'Doe, Jane', 'en');
        app(RelationService::class)->create($fonds, 'creation', $agent, [], $this->editor);
        app(RelationService::class)->create($series, 'associated-record', $part, [], $this->editor);
        $audit = DB::table('audit_log')->count();

        foreach (['ead', 'ead3'] as $format) {
            $xml = $this->actingAs($this->editor)->get("/records/{$fonds->id}/export/$format?drafts=1")->getContent();
            $import = $this->commit($this->upload($xml, 'ead', [], $format));
            $this->assertEquals(['created' => 0, 'updated' => 0, 'unchanged' => 3, 'rejected' => 0], $import->report['actions'], "$format reads back unchanged");
            $this->assertSame([], collect($import->report['issues'])->where('kind', '!=', 'note')->values()->all(), "$format: every field read");
        }
        $this->assertSame([3, 1, 2], [RecordResource::count(), Agent::count(), Relation::count()]);
        $this->assertSame($audit, DB::table('audit_log')->count(), 'nothing was written');

        $xml = str_replace('<p>Two.</p>', '<p>Two, changed.</p>', $this->actingAs($this->editor)->get("/records/{$fonds->id}/export/ead?drafts=1")->getContent());
        $this->assertEquals(['created' => 0, 'updated' => 1, 'unchanged' => 2, 'rejected' => 0], $this->commit($this->upload($xml, 'ead'))->report['actions']);
        $this->assertSame("One.\n\nTwo, changed.", $fonds->fresh()->described('scope-and-content', ['en']));
    }

    public function test_a_finding_aid_larger_than_a_part_is_checked_and_imported_whole_with_every_unit_in_its_parent(): void
    {
        // 1 + 3 series + 3 x 40 files = 124 units: seven parts of 20, files whose series is in an earlier part, a new
        // subject used in every part. The dry run checks each part inside its real parents and writes nothing.
        $series = '';
        foreach (range(1, 3) as $s) {
            $files = '';
            foreach (range(1, 40) as $f) {
                $files .= "<c02 level=\"file\" id=\"f$s-$f\"><did><unittitle>File $s.$f</unittitle><unitid>$s.$f</unitid></did><controlaccess><subject>Parish business</subject></controlaccess></c02>";
            }
            $series .= "<c01 level=\"series\" id=\"s$s\"><did><unittitle>Series $s</unittitle><unitid>$s</unitid></did>$files</c01>";
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><ead><eadheader><eadid>BIG</eadid></eadheader><archdesc level="fonds"><did><unittitle>Big fonds</unittitle><unitid>BIG</unitid></did><dsc>'.$series.'</dsc></archdesc></ead>';

        // Savepoint locks held by this connection, sampled as the import runs: about one per unit, not one per write.
        $most = 0;
        $sampling = false;
        DB::listen(function () use (&$most, &$sampling) {
            if (! $sampling) {
                $sampling = true;
                $most = max($most, (int) DB::selectOne("SELECT count(*) AS n FROM pg_locks WHERE pid = pg_backend_pid() AND locktype = 'transactionid'")->n);
                $sampling = false;
            }
        });
        $import = $this->upload($xml, 'ead', ['create_terms' => '1']);
        $this->assertEquals(['created' => 124, 'updated' => 0, 'unchanged' => 0, 'rejected' => 0], $import->report['actions']);
        $this->assertSame(0, RecordResource::count(), 'a dry run writes nothing');
        $this->assertLessThan(300, $most, 'a lock per unit, not per write (each unit once took about 75)');
        $this->assertCount(1, collect($import->report['issues'])->where('kind', 'created')->where('value', 'Parish business'), 'the new subject is reported once');

        $import = $this->commit($import);
        $this->assertEquals(['created' => 124, 'updated' => 0, 'unchanged' => 0, 'rejected' => 0], $import->report['actions']);
        $top = RecordResource::whereNull('parent_id')->sole();
        $this->assertSame(3, $top->children()->count());
        foreach ($top->children as $child) {
            $this->assertSame(40, $child->children()->count(), 'every file is in its series');
        }
        $this->assertSame(1, Concept::whereHas('labels', fn ($q) => $q->where('pref_label', 'Parish business'))->count(), 'one subject, used by all');
    }

    public function test_an_archives_nationales_finding_aid_keeps_its_reference_code_spaced_dates_and_occupations(): void
    {
        // As the Archives nationales write it: the code only as a typed unitid, an interval with spaces around the slash, trades indexed as occupations, which become subjects.
        $xml = '<?xml version="1.0" encoding="UTF-8"?><ead><eadheader><eadid>FRAN_IR_X</eadid></eadheader><archdesc level="fonds"><did><unittitle>Minutes de l\'étude XII</unittitle>'
            .'<unitid type="cote-de-consultation">MC/ET/XII</unitid><unitdate normal="1570-01-01 / 1947-12-31">1570-1947</unitdate></did>'
            .'<controlaccess><occupation authfilenumber="d699msjnau1" source="FRAN_RI_010">serrurier</occupation></controlaccess></archdesc></ead>';
        $import = $this->commit($this->upload($xml, 'ead', ['create_terms' => '1']));
        $this->assertEquals(['created' => 1, 'updated' => 0, 'unchanged' => 0, 'rejected' => 0], $import->report['actions']);
        $unit = RecordResource::sole();
        $this->assertSame('MC/ET/XII', $unit->identifier);
        $this->assertSame(0, $unit->identifiers()->whereHas('identifierType', fn ($q) => $q->where('notation', 'other'))->count(), 'not kept twice');
        $this->assertSame('1570/1947', $unit->dates()->sole()->normalized_value, 'whole years, as the interval runs from 1 January to 31 December');
        $concept = Concept::whereHas('labels', fn ($q) => $q->where('pref_label', 'serrurier'))->sole();
        $this->assertSame('subject', $concept->vocabulary->kind->notation, 'occupation types are for people');
        $this->assertTrue(Relation::where(['source_id' => $unit->id, 'target_id' => $concept->id])->exists(), 'the unit is about the trade');
    }

    public function test_a_foreign_ead_2002_finding_aid_without_a_namespace(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <ead><eadheader><eadid>US-XX-1</eadid></eadheader>
            <archdesc level="collection"><did><unittitle>Smith family papers</unittitle><unitid>SFP</unitid>
              <unitdate normal="19020101/19501231" type="inclusive">1902-1950</unitdate><physdesc><extent>2 linear feet</extent></physdesc>
              <origination><famname>Smith family</famname></origination><dao href="x.jpg"/></did>
              <scopecontent><head>Scope</head><p>Letters.</p><p>Diaries.</p></scopecontent>
              <controlaccess><persname>Smith, John</persname><genreform>Diaries</genreform><geogname>Riverside</geogname></controlaccess>
              <dsc><c01 level="series" id="s1"><did><unittitle>Letters</unittitle></did>
                <c02 level="file"><did><unittitle>Letters, 1902</unittitle><container type="box">1</container></did></c02></c01></dsc>
            </archdesc></ead>
            XML;
        $import = $this->upload($xml, 'ead', ['create_terms' => '1']);
        $this->assertEquals(['created' => 3, 'updated' => 0, 'unchanged' => 0, 'rejected' => 0], $import->report['actions']);
        $issues = collect($import->report['issues']);
        $this->assertTrue($issues->contains(fn ($i) => $i['field'] === 'dao' && $i['kind'] === 'left-out'), 'dao is listed');
        $this->assertTrue($issues->contains(fn ($i) => $i['field'] === 'physicalObjectName' && $i['value'] === '1' && $i['kind'] === 'created'), 'the box becomes a container');
        $this->assertTrue($issues->contains(fn ($i) => $i['field'] === 'genre' && $i['value'] === 'Diaries' && $i['problem'] === 'a new documentary form type'));
        $this->commit($import);
        $top = RecordResource::whereNull('parent_id')->sole();
        $this->assertSame(['collection', 'SFP', '1902/1950', "Letters.\n\nDiaries.", '2 linear feet'],
            [$top->level->notation, $top->identifier, $top->dates()->sole()->normalized_value, $top->described('scope-and-content', ['en']), $top->described('extent', ['en'])]);
        $this->assertSame(['family', 'person'], Agent::all()->map->typeNotation()->sort()->values()->all(), 'EAD says which kind of agent a name is');
        $this->assertSame('Letters, 1902', RecordResource::where('parent_id', RecordResource::where('parent_id', $top->id)->sole()->id)->sole()->described('title', ['en']));
    }

    public function test_who_may_import_and_bad_files(): void
    {
        $reader = app(AccountService::class)->create($this->institution, 'Reader', 'reader@example.org', 'correct horse battery staple');
        $this->actingAs($reader)->get('/imports/new')->assertForbidden();
        $this->actingAs($this->editor)->get('/imports/new')->assertOk()->assertSee('Upload and make a dry run');

        $this->actingAs($this->editor)->post('/imports', ['file' => UploadedFile::fake()->createWithContent('a.csv', "title\n\xE9t\xE9"), 'format' => 'csv',
            'repository' => $this->holder->id, 'language' => 'en'])->assertSessionHasErrors('file');
        $import = $this->upload('<html>not a finding aid</html>', 'ead');
        $this->assertSame('rejected', $import->report['issues'][0]['kind']);
        $this->actingAs($this->editor)->get("/imports/{$import->id}")->assertOk()->assertDontSee('Import now');

        $other = Institution::create(['name' => 'Elsewhere', 'base_iri' => 'https://elsewhere.example.org']);
        app(RoleService::class)->seedDefaults($other);
        $stranger = app(AccountService::class)->create($other, 'Stranger', 'stranger@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($stranger, 'editor');
        $this->actingAs($stranger)->get("/imports/{$import->id}")->assertNotFound();
        $this->actingAs($stranger)->post("/imports/{$import->id}/commit")->assertNotFound();
    }
}
