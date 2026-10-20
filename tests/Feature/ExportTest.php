<?php

// OpenRiC Workspace - export of units of description: EAD 2002, EAD3, CSV, Dublin Core and MODS.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\AgentService;
use App\Description\DateService;
use App\Description\IdentifierService;
use App\Description\PlaceService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Exchange\DescriptionCsv;
use App\Models\Agent;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\User;
use App\Relations\RelationService;
use App\Vocabulary\ConceptService;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private Institution $institution;

    private Repository $holder;

    private RecordResource $fonds;

    private RecordResource $series;

    private RecordResource $file;

    private User $editor;

    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        $accounts = app(AccountService::class);
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->editor = $accounts->create($this->institution, 'Editor', 'editor@example.org', 'correct horse battery staple');
        $accounts->grantTemplateRole($this->editor, 'editor');
        $this->reader = $accounts->create($this->institution, 'A Reader', 'reader@example.org', 'correct horse battery staple');

        $this->holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        app(IdentifierService::class)->add($this->holder, ['type' => 'isil', 'value' => 'ZA-RA']);
        $records = app(RecordService::class);
        $this->fonds = $records->create($this->institution, self::LEVEL.'fonds', 'Riverside Parish Council records', 'en', null, $this->holder, 'RPC');
        $this->series = $records->create($this->institution, self::LEVEL.'series', 'Minutes', 'en', $this->fonds, null, '1');
        $this->file = $records->create($this->institution, self::LEVEL.'part', 'Minutes & <draft> notes, 1902', 'en', $this->series, null, '3');
        $records->describe($this->fonds, ['scope-and-content' => "Minutes and correspondence of the council.\n\nAlso accounts.", 'extent' => '3 boxes',
            'languages-of-material' => 'English and Dutch.', 'rules-or-conventions' => 'ISAD(G), second edition.', 'conditions-governing-access' => 'Open.'], 'en');
        $records->describe($this->fonds, ['title' => 'Rekords van die Riverside-gemeenteraad', 'scope-and-content' => 'Notules en korrespondensie.'], 'af');
        app(DateService::class)->add($this->fonds, ['property' => 'creation', 'normalized' => '1902/1950', 'expressed' => '1902 to 1950', 'language' => 'en']);

        $agents = app(AgentService::class);
        $council = $agents->create($this->institution, 'corporate-body', 'Riverside Parish Council', 'en');
        $mayor = $agents->create($this->institution, 'person', 'Engelbrecht, Johanna', 'en');
        $relations = app(RelationService::class);
        $relations->create($this->fonds, 'creation', $council, [], $this->editor);
        $relations->create($this->fonds, 'subject', $mayor, [], $this->editor);
        $town = app(PlaceService::class)->create($this->institution, 'Riverside', 'en');
        $relations->create($town, 'place', $this->fonds, [], $this->editor);
        $concepts = app(ConceptService::class);
        $subject = $concepts->create($concepts->schemeOfKind($this->institution, 'subject'), ['label' => 'Local government', 'language' => 'en']);
        $relations->create($this->fonds, 'subject', $subject, [], $this->editor);
        $relations->create($this->series, 'associated-record', $this->file, [], $this->editor);
        // Proposed by an import, not yet reviewed: never exported.
        $proposed = $agents->create($this->institution, 'person', 'Somebody, Unreviewed', 'en');
        $relations->create($this->series, 'creation', $proposed, [], null, ['origin' => 'import', 'label' => 'test']);

        $publication = app(PublicationService::class);
        foreach ([$this->fonds, $council, $mayor, $town, $this->holder] as $entity) {
            $publication->apply($entity, 'publish', null, true, $this->editor);
        }
    }

    /** @return list<list<string>> */
    private function rows(string $csv): array
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    private function assertValid(string $xml, string $schema): DOMDocument
    {
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($xml), 'well-formed');
        libxml_use_internal_errors(true);
        $valid = $document->schemaValidate(resource_path("schemas/$schema"));
        $errors = array_map(fn ($e) => trim($e->message).' (line '.$e->line.')', libxml_get_errors());
        libxml_clear_errors();
        $this->assertTrue($valid, "valid against $schema:\n".implode("\n", $errors));

        return $document;
    }

    public function test_ead_2002_and_ead3_are_valid_and_carry_the_hierarchy(): void
    {
        foreach (['ead' => 'ead2002.xsd', 'ead3' => 'ead3.xsd'] as $format => $schema) {
            $response = $this->actingAs($this->editor)->get("/records/{$this->fonds->id}/export/$format?drafts=1")->assertOk()
                ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
            $xml = $response->getContent();
            $this->assertValid($xml, $schema);
            $this->assertStringContainsString('Riverside Parish Council records', $xml);
            $this->assertStringContainsString('Minutes &amp; &lt;draft&gt; notes, 1902', $xml);
            $this->assertStringContainsString('normal="1902/1950"', $xml);
            $this->assertStringContainsString($this->fonds->iri, $xml);
            $this->assertStringContainsString('otherlevel="Part"', $xml);
            $this->assertStringContainsString('Local government', $xml);
            $this->assertStringContainsString('Engelbrecht, Johanna', $xml);
            $this->assertStringContainsString('encodinganalog="3.3.1"', $xml);
            $this->assertStringNotContainsString('Somebody, Unreviewed', $xml, 'a proposed relation is not exported');
        }
    }

    public function test_a_repository_without_an_isil_gives_no_agency_code(): void
    {
        // Found on live data: a local identifier is not an ISO 15511 code, and EAD 2002 refuses one with a slash.
        $repository = app(RepositoryService::class)->create($this->institution, 'Town Hall', 'en', '2025-11-22/131');
        $unit = app(RecordService::class)->create($this->institution, self::LEVEL.'fonds', 'Town Hall records', 'en', null, $repository, 'TH');
        foreach (['ead' => 'ead2002.xsd', 'ead3' => 'ead3.xsd'] as $format => $schema) {
            $xml = $this->actingAs($this->editor)->get("/records/{$unit->id}/export/$format?drafts=1")->getContent();
            $this->assertValid($xml, $schema);
            $this->assertStringNotContainsString('agencycode', $xml);
            $this->assertStringNotContainsString('repositorycode', $xml);
        }
    }

    public function test_a_language_is_chosen_and_a_value_not_in_it_comes_from_the_original(): void
    {
        $xml = $this->actingAs($this->editor)->get("/records/{$this->fonds->id}/export/ead3?language=af")->getContent();
        $this->assertValid($xml, 'ead3.xsd');
        $this->assertStringContainsString('<titleproper>Rekords van die Riverside-gemeenteraad</titleproper>', $xml);
        $this->assertStringContainsString('<p>Notules en korrespondensie.</p>', $xml);
        $this->assertStringContainsString('<physdesc encodinganalog="3.1.5" lang="en">3 boxes</physdesc>', $xml, 'EAD3 marks a value in another language');
        $this->actingAs($this->editor)->get("/records/{$this->fonds->id}/export/ead?language=not_a_tag")->assertNotFound();
    }

    public function test_drafts_embargoed_authority_records_and_other_institutions_stay_out(): void
    {
        app(RecordService::class)->describe($this->fonds, ['appraisal' => 'Duplicates destroyed in 1980.'], 'en');
        $mayor = Agent::whereHas('names', fn ($q) => $q->where('value', 'Engelbrecht, Johanna'))->sole();
        $mayor->update(['embargo_until' => now()->addYear()]);

        $read = fn (User $user, string $query = '') => $this->actingAs($user)->get("/records/{$this->fonds->id}/export/ead$query")->assertOk()->getContent();
        $this->assertStringNotContainsString('Duplicates destroyed', $read($this->reader));
        $this->assertStringNotContainsString('Duplicates destroyed', $read($this->reader, '?drafts=1'), 'drafts need view-draft');
        $this->assertStringNotContainsString('Duplicates destroyed', $read($this->editor));
        $this->assertStringContainsString('Duplicates destroyed', $read($this->editor, '?drafts=1'));
        $this->assertStringNotContainsString('Engelbrecht', $read($this->reader), 'an embargoed authority record is left out');
        $this->assertStringContainsString('Engelbrecht', $read($this->editor));

        $other = Institution::create(['name' => 'Elsewhere', 'base_iri' => 'https://elsewhere.example.org']);
        app(RoleService::class)->seedDefaults($other);
        $stranger = app(AccountService::class)->create($other, 'Stranger', 'stranger@example.org', 'correct horse battery staple');
        $this->actingAs($stranger)->get("/records/{$this->fonds->id}/export/ead")->assertNotFound();
        $this->actingAs($stranger)->get("/records/{$this->fonds->id}/export")->assertNotFound();
    }

    public function test_csv_has_atom_columns_a_row_per_unit_and_translation_rows(): void
    {
        $rows = fn (string $csv) => $this->rows($csv);
        $csv = $this->actingAs($this->editor)->get("/records/{$this->fonds->id}/export/csv")->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();
        [$header, $fonds, $series, $file] = $rows($csv);
        $this->assertSame(array_keys(DescriptionCsv::COLUMNS), $header);
        $at = fn (array $row, string $column) => $row[array_search($column, $header, true)];
        $this->assertSame(['', $this->fonds->id, $this->series->id], [$at($fonds, 'parentId'), $at($series, 'parentId'), $at($file, 'parentId')]);
        $this->assertSame(['RPC', 'Riverside Parish Council records', 'Fonds', '3 boxes', 'Riverside Archives', 'ZA-RA'],
            [$at($fonds, 'identifier'), $at($fonds, 'title'), $at($fonds, 'levelOfDescription'), $at($fonds, 'extentAndMedium'), $at($fonds, 'repository'), $at($fonds, 'institutionIdentifier')]);
        $this->assertSame("Minutes and correspondence of the council.\n\nAlso accounts.", $at($fonds, 'scopeAndContent'));
        $this->assertSame(['Local government', 'Riverside', 'Engelbrecht, Johanna'], [$at($fonds, 'subjectAccessPoints'), $at($fonds, 'placeAccessPoints'), $at($fonds, 'nameAccessPoints')]);
        $this->assertSame(['Creation', '1902 to 1950', '1902-01-01', '1950-12-31', 'Riverside Parish Council'],
            [$at($fonds, 'eventTypes'), $at($fonds, 'eventDates'), $at($fonds, 'eventStartDates'), $at($fonds, 'eventEndDates'), $at($fonds, 'eventActors')]);
        $this->assertSame([$this->fonds->iri, 'Published', 'en'], [$at($fonds, 'persistentIri'), $at($fonds, 'publicationStatus'), $at($fonds, 'culture')]);
        $this->assertSame('Minutes & <draft> notes, 1902', $at($file, 'title'));

        $every = $rows($this->actingAs($this->editor)->get("/records/{$this->fonds->id}/export/csv?rows=every-language")->getContent());
        $this->assertCount(5, $every, 'the fonds has a row in English and one in Afrikaans');
        $this->assertSame([$this->fonds->id, 'af', 'Rekords van die Riverside-gemeenteraad', 'Notules en korrespondensie.', ''],
            [$at($every[2], 'legacyId'), $at($every[2], 'culture'), $at($every[2], 'title'), $at($every[2], 'scopeAndContent'), $at($every[2], 'extentAndMedium')]);
    }

    public function test_dublin_core_and_mods_are_valid(): void
    {
        $dc = $this->actingAs($this->editor)->get("/records/{$this->fonds->id}/export/dc")->assertOk()->getContent();
        $this->assertValid($dc, 'oai_dc.xsd');
        $this->assertStringContainsString('<dc:title xml:lang="en">Riverside Parish Council records</dc:title>', $dc);
        $this->assertStringContainsString('<dc:date>1902/1950</dc:date>', $dc);
        $this->assertStringContainsString('<dc:coverage>Riverside</dc:coverage>', $dc);

        $mods = $this->actingAs($this->editor)->get("/records/{$this->series->id}/export/mods")->assertOk()->getContent();
        $this->assertValid($mods, 'mods-3-8.xsd');
        $this->assertStringContainsString('<relatedItem type="host" xlink:href="'.$this->fonds->iri.'">', $mods);
        $mods = $this->actingAs($this->editor)->get("/records/{$this->fonds->id}/export/mods")->getContent();
        $this->assertValid($mods, 'mods-3-8.xsd');
        $this->assertStringContainsString('<dateCreated encoding="edtf" keyDate="yes">1902/1950</dateCreated>', $mods);
        $this->assertStringContainsString('<physicalLocation valueURI="'.$this->holder->iri.'">Riverside Archives</physicalLocation>', $mods);
    }

    public function test_every_unit_of_the_institution_exports_as_csv(): void
    {
        $other = app(RepositoryService::class)->create($this->institution, 'Town Hall', 'en');
        app(RecordService::class)->create($this->institution, self::LEVEL.'fonds', 'Town Hall records', 'en', null, $other, 'TH');
        $this->actingAs($this->reader)->get('/records/export')->assertOk()->assertSee('Download CSV')->assertDontSee('Include drafts');
        $csv = $this->actingAs($this->editor)->get('/records/export?download=1&drafts=1')->assertOk()->getContent();
        $this->assertCount(5, $this->rows($csv), 'a header and four units');
        $this->assertStringContainsString('Town Hall records', $csv);
        $csv = $this->actingAs($this->editor)->get("/records/export?download=1&drafts=1&repository={$other->id}")->getContent();
        $this->assertStringContainsString('Town Hall records', $csv);
        $this->assertStringNotContainsString('Riverside Parish Council records', $csv);
        $this->actingAs($this->editor)->get("/records/{$this->fonds->id}/export")->assertOk()->assertSee('Download EAD3')->assertSee('Include drafts');
    }
}
