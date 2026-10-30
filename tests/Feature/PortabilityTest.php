<?php

// OpenRiC Workspace - the Portability profile 0.43.0 (roadmap phase 11b, ADR 0013): a record exported as RiC-O, in
// JSON-LD, Turtle and RDF/XML, reads back through the import and writes nothing; the same graph under another IRI
// makes a new record with its title, identifier, description, dates and creators; what the import has no place for is
// listed; the dataset descriptor is valid against the specification's schema and names every distribution; the
// change feed lists the changes and the pinned standards.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\AgentService;
use App\Description\ContactPointService;
use App\Description\DateService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Agent;
use App\Models\Import;
use App\Models\Institution;
use App\Models\Name;
use App\Models\RecordResource;
use App\Models\Relation;
use App\Models\Repository;
use App\Models\User;
use App\Relations\RelationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Tests\TestCase;

class PortabilityTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private const API = '/api/ric/v1';

    private Institution $institution;

    private Repository $holder;

    private RecordResource $fonds;

    private Agent $council;

    private Agent $clerk;

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
        app(RepositoryService::class)->describe($this->holder, ['history' => 'Founded in 1960.', 'opening-times' => 'Weekdays, 9 to 4.'], 'en');
        app(ContactPointService::class)->save($this->holder, ['label' => 'Main', 'street_address' => '1 Church Street', 'city' => 'Riverside', 'country_code' => 'ZA',
            'email' => 'archives@example.org', 'is_primary' => true]);
        $records = app(RecordService::class);
        $this->fonds = $records->create($this->institution, self::LEVEL.'fonds', 'Riverside Parish Council records', 'en', null, $this->holder, 'RPC');
        $records->describe($this->fonds, ['scope-and-content' => "Minutes.\n\nCorrespondence.", 'extent' => '3 boxes', 'archivists-note' => 'Described in 2026.',
            'dates-expressed' => 'about 1902 to 1950'], 'en');
        $records->describe($this->fonds, ['title' => 'Rekords van die Riverside-gemeenteraad'], 'af');
        app(DateService::class)->add($this->fonds, ['property' => 'creation', 'normalized' => '1902/1950-06']);
        app(DateService::class)->add($this->fonds, ['property' => 'accumulation', 'normalized' => '1950', 'expressed' => 'in 1950', 'language' => 'en']);
        $series = $records->create($this->institution, self::LEVEL.'series', 'Minutes', 'en', $this->fonds, null, '1');
        $agents = app(AgentService::class);
        $this->council = $agents->create($this->institution, 'corporate-body', 'Riverside Parish Council', 'en', 'RPC-A');
        $agents->describe($this->council, ['agent-history' => 'Established in 1902.', 'agent-parallel-name' => 'Riverside-gemeenteraad',
            'agent-standardised-name' => 'Riverside (South Africa). Parish Council'], 'en');
        app(DateService::class)->add($this->council, ['property' => 'existence', 'normalized' => '1902/1950']);
        $this->clerk = $agents->create($this->institution, 'person', 'Engelbrecht, Johanna', 'en');
        app(DateService::class)->add($this->clerk, ['property' => 'birth', 'normalized' => '1871']);
        app(DateService::class)->add($this->clerk, ['property' => 'death', 'normalized' => '1949-03-02']);
        app(RelationService::class)->create($this->fonds, 'creation', $this->council, [], $this->editor);
        // An accumulator is a creator in RiC-O too: it must not come back as a second relation.
        app(RelationService::class)->create($this->fonds, 'accumulation', $this->clerk, [], $this->editor);
        foreach ([$this->holder, $this->council, $this->clerk, $this->fonds, $series] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->editor);
        }
    }

    private function upload(string $content, string $name, bool $commit = true): Import
    {
        $location = $this->actingAs($this->editor)->post('/imports', ['file' => UploadedFile::fake()->createWithContent($name, $content), 'format' => 'ric-o',
            'language' => 'en', 'repository' => $this->holder->id])->assertRedirect()->assertSessionHasNoErrors()->headers->get('Location');
        $import = Import::findOrFail(basename($location));
        if ($commit) {
            $this->actingAs($this->editor)->post("/imports/{$import->id}/commit")->assertRedirect(route('imports.show', $import));
        }

        return $import->fresh();
    }

    private function export(string $format): string
    {
        return $this->get(self::API."/records/{$this->fonds->id}/export?format=$format")->assertOk()->getContent();
    }

    public function test_a_record_exported_as_json_ld_turtle_or_rdf_xml_reads_back_and_writes_nothing(): void
    {
        $audit = DB::table('audit_log')->count();
        $counts = fn () => [RecordResource::count(), Agent::count(), Repository::count(), Relation::count(), Name::count()];
        $before = $counts();
        foreach (['jsonld' => 'fonds.jsonld', 'ttl' => 'fonds.ttl', 'rdf' => 'fonds.rdf'] as $format => $name) {
            $import = $this->upload($this->export($format), $name);
            // The fonds, its holder and its two creators; the series is only named, as the unit inside.
            $this->assertEquals(['created' => 0, 'updated' => 0, 'unchanged' => 4, 'rejected' => 0], $import->report['actions'], "$format reads back unchanged");
            $this->assertSame([], collect($import->report['issues'])->where('kind', '!=', 'note')->values()->all(), "$format: every property read");
            $this->assertTrue($import->report['reconciliation']['holds'] ?? true, "$format reconciles");
        }
        $this->assertSame($before, $counts());
        $this->assertSame($audit, DB::table('audit_log')->count(), 'nothing was written');
    }

    public function test_the_same_graph_under_another_iri_is_a_new_record_with_its_title_identifier_description_dates_and_creators(): void
    {
        $copy = str_replace($this->fonds->iri, 'https://elsewhere.example.org/record/9', $this->export('ttl'));
        $import = $this->upload($copy, 'copy.ttl');
        $this->assertEquals(['created' => 1, 'updated' => 0, 'unchanged' => 3, 'rejected' => 0], $import->report['actions']);
        $new = RecordResource::where('id', '!=', $this->fonds->id)->whereNull('parent_id')->sole();
        $this->assertSame(['Riverside Parish Council records', 'Rekords van die Riverside-gemeenteraad', "Minutes.\n\nCorrespondence.", '3 boxes', 'RPC', 'fonds'],
            [$new->described('title', ['en']), $new->described('title', ['af']), $new->described('scope-and-content', ['en']), $new->described('extent', ['en']),
                $new->identifier, $new->level->notation]);
        $this->assertSame($this->holder->id, $new->repository_id, 'held by the repository with the IRI it names');
        $this->assertEqualsCanonicalizing(['1902/1950-06', '1950'], $new->dates()->pluck('normalized_value')->all());
        $this->assertTrue(Relation::where(['source_id' => $new->id, 'target_id' => $this->council->id])->exists(), 'created by the council, found by its IRI');
        $this->assertTrue(Relation::where(['source_id' => $new->id, 'target_id' => $this->clerk->id])->exists());
    }

    public function test_what_the_import_has_no_place_for_is_listed(): void
    {
        $graph = <<<'TTL'
            @prefix rico: <https://www.ica.org/standards/RiC/ontology#> .
            <https://elsewhere.example.org/record/1> a rico:Record ; rico:title "A letter"@en ; rico:identifier "L1" ;
                rico:hasDocumentaryFormType <https://elsewhere.example.org/form/letter> ;
                rico:hasOrHadSubject <https://elsewhere.example.org/place/1> .
            <https://elsewhere.example.org/place/2> a rico:Place ; rico:name "Riverside" .
            TTL;
        $import = $this->upload($graph, 'letter.ttl', false);
        $issues = collect($import->report['issues'])->where('kind', 'left-out');
        $this->assertEqualsCanonicalizing(['rico:hasDocumentaryFormType', 'rico:hasOrHadSubject', 'rico:Place'], $issues->pluck('field')->all(), 'listed, not lost silently');
        $this->assertSame(0, RecordResource::where('id', '!=', $this->fonds->id)->whereNull('parent_id')->count(), 'a dry run writes nothing');
        $this->actingAs($this->editor)->post('/imports', ['file' => UploadedFile::fake()->createWithContent('bad.ttl', '<not turtle'), 'format' => 'ric-o', 'language' => 'en',
            'repository' => $this->holder->id]);
        $this->assertSame('rejected', collect(Import::where('file_name', 'bad.ttl')->sole()->report['issues'])->first()['kind'] ?? null, 'a file that is not RDF is refused whole');
    }

    public function test_the_dataset_descriptor_and_the_change_feed(): void
    {
        $response = $this->get(self::API.'/dataset')->assertOk();
        $result = (new Validator)->validate(json_decode($response->getContent()), file_get_contents(base_path('tests/Fixtures/openric-0.56.0/dataset.schema.json')));
        $this->assertTrue($result->isValid(), json_encode($result->isValid() ? [] : (new ErrorFormatter)->format($result->error())));
        $dataset = $response->json();
        $this->assertSame(['application/ld+json', 'application/ld+json', 'text/turtle', 'application/rdf+xml', 'application/xml'], array_column($dataset['dcat:distribution'], 'dcat:mediaType'));
        $this->assertContains(['@id' => 'https://www.ica.org/standards/RiC/ontology', 'rdfs:label' => 'RiC-O 1.1 (2025-05-22)'], $dataset['dcterms:conformsTo']);
        $this->assertSame(trim(file_get_contents(base_path('VERSION'))), $dataset['dcat:version']);
        foreach ($dataset['dcat:distribution'] as $distribution) {
            $this->get(str_replace('{UUID}', $this->fonds->id, $distribution['dcat:accessURL']).(str_contains($distribution['dcat:accessURL'], '/oai') ? '?verb=Identify' : ''))->assertOk();
        }

        $feed = $this->get(self::API.'/changelog')->assertOk()->json();
        $this->assertSame(['dataset_version', 'openric_spec_version', 'pinned_standards', 'governance_pin', 'deprecated_entities', 'changes'], array_keys($feed));
        $this->assertSame('0.56.0', $feed['openric_spec_version']);
        $versions = array_column($feed['changes'], 'version');
        $newestFirst = $versions;
        usort($newestFirst, fn ($a, $b) => version_compare($b, $a));
        $this->assertSame($newestFirst, $versions, 'newest first');
        $this->assertSame($versions[0], $feed['dataset_version'], 'this release is in the feed');
        $this->assertFileExists(base_path('conformance/governance-pin.md'));
    }
}
