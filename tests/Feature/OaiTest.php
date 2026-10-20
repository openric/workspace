<?php

// OpenRiC Workspace - Export-Only 0.9.0: OAI-PMH 2.0 and the per-record RiC-O export (its nine checks, section 6).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Api\Rdf;
use App\Description\AgentService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Agent;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\User;
use App\Relations\RelationService;
use DOMDocument;
use DOMXPath;
use EasyRdf\Graph;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class OaiTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private const OAI = '/api/ric/v1/oai';

    private Institution $institution;

    private Repository $holder;

    private RecordResource $fonds;

    private RecordResource $series;

    private Agent $council;

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
        $records = app(RecordService::class);
        $this->fonds = $records->create($this->institution, self::LEVEL.'fonds', 'Riverside Parish Council records', 'en', null, $this->holder, 'RPC');
        $records->describe($this->fonds, ['scope-and-content' => 'Minutes ]]> and correspondence.'], 'en');
        $this->series = $records->create($this->institution, self::LEVEL.'series', 'Minutes', 'en', $this->fonds, null, '1');
        $records->create($this->institution, self::LEVEL.'series', 'Not published', 'en', $this->fonds, null, '2');
        $this->council = app(AgentService::class)->create($this->institution, 'corporate-body', 'Riverside Parish Council', 'en');
        app(AgentService::class)->describe($this->council, ['agent-history' => 'Established in 1902.'], 'en');
        app(RelationService::class)->create($this->fonds, 'creation', $this->council, [], $this->editor);
        foreach ([$this->holder, $this->council, $this->fonds, $this->series] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->editor);
        }
    }

    /** The response, valid against OAI-PMH 2.0 with oai_dc, oai-identifier and the rico_ld wrapper, checked strictly. */
    private function oai(string $query, string $method = 'get'): DOMXPath
    {
        $response = $method === 'post'
            ? $this->call('POST', self::OAI, [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], $query)
            : $this->get(self::OAI.'?'.$query);
        $response->assertOk()->assertHeader('Content-Type', 'text/xml; charset=utf-8');
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($response->getContent()));
        libxml_use_internal_errors(true);
        $valid = $document->schemaValidate(base_path('tests/Fixtures/oai/driver.xsd'));
        $errors = array_map(fn ($e) => trim($e->message), libxml_get_errors());
        libxml_clear_errors();
        $this->assertTrue($valid, "valid OAI-PMH for $query: ".implode('; ', $errors));
        $xpath = new DOMXPath($document);
        foreach (['o' => 'http://www.openarchives.org/OAI/2.0/', 'dc' => 'http://purl.org/dc/elements/1.1/', 'i' => 'http://www.openarchives.org/OAI/2.0/oai-identifier',
            'x' => 'https://openric.org/ns/ext/v1#'] as $prefix => $namespace) {
            $xpath->registerNamespace($prefix, $namespace);
        }

        return $xpath;
    }

    private function text(DOMXPath $xpath, string $path): string
    {
        return trim((string) $xpath->evaluate("string($path)"));
    }

    private function id(RecordResource $unit): string
    {
        return 'oai:archives.example.org:'.$unit->id;
    }

    public function test_identify_and_metadata_formats(): void
    {
        $identify = $this->oai('verb=Identify');
        $this->assertSame(['2.0', 'no', 'YYYY-MM-DDThh:mm:ssZ', 'Riverside Archives', 'archives.example.org', route('api.oai')],
            [$this->text($identify, '//o:protocolVersion'), $this->text($identify, '//o:deletedRecord'), $this->text($identify, '//o:granularity'),
                $this->text($identify, '//o:repositoryName'), $this->text($identify, '//i:repositoryIdentifier'), $this->text($identify, '//o:baseURL')]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $this->text($identify, '//o:earliestDatestamp'));
        $formats = $this->oai('verb=ListMetadataFormats');
        $this->assertSame(['oai_dc', 'rico_ld'], array_map(fn ($n) => $n->textContent, iterator_to_array($formats->query('//o:metadataPrefix'))));
        $this->assertSame('idDoesNotExist', $this->text($this->oai('verb=ListMetadataFormats&identifier=oai:archives.example.org:nothing'), '//o:error/@code'));
    }

    public function test_records_as_oai_dc_and_rico_ld_and_the_unpublished_stay_out(): void
    {
        $dc = $this->oai('verb=ListRecords&metadataPrefix=oai_dc');
        $this->assertSame(['Minutes', 'Riverside Parish Council records'], collect(iterator_to_array($dc->query('//dc:title')))->map->textContent->sort()->values()->all());
        $this->assertSame('Riverside Parish Council', $this->text($dc, "//o:record[o:header/o:identifier='{$this->id($this->fonds)}']//dc:creator"));
        $this->assertSame(2, $dc->query('//o:record')->length, 'the unpublished unit is not harvested');

        $ld = $this->oai('verb=GetRecord&metadataPrefix=rico_ld&identifier='.$this->id($this->fonds));
        $dump = json_decode($this->text($ld, '//x:jsonld'), true);
        $this->assertSame([$this->fonds->iri, $this->holder->iri, $this->council->iri], array_column($dump['@graph'], '@id'));
        $this->assertSame('Minutes ]]> and correspondence.', $dump['@graph'][0]['rico:scopeAndContent']['@value'], 'the CDATA end marker survives');
        $this->assertSame(['records', 'records:'.$this->fonds->id], collect(iterator_to_array($ld->query('//o:setSpec')))->map->textContent->all());
        $this->assertSame('Minutes', $this->text($this->oai('verb=GetRecord&metadataPrefix=oai_dc&identifier='.$this->id($this->series)), '//dc:title'));

        $identifiers = $this->oai('verb=ListIdentifiers&metadataPrefix=rico_ld&set=records:'.$this->fonds->id);
        $this->assertSame(2, $identifiers->query('//o:header')->length);
        $sets = $this->oai('verb=ListSets');
        $this->assertSame(['records', 'records:'.$this->fonds->id], collect(iterator_to_array($sets->query('//o:setSpec')))->map->textContent->all());
        $this->assertSame('Minutes', $this->text($this->oai('verb=ListRecords&metadataPrefix=oai_dc', 'post'), "//o:record[o:header/o:identifier='{$this->id($this->series)}']//dc:title"));
    }

    public function test_dates_errors_and_the_protocols_own_envelope(): void
    {
        $errors = [
            'verb=Frobnicate' => 'badVerb', '' => 'badVerb', 'verb=ListRecords' => 'badArgument', 'verb=Identify&verb=Identify' => 'badVerb',
            'verb=ListRecords&metadataPrefix=oai_dc&metadataPrefix=rico_ld' => 'badArgument', 'verb=Identify&set=x' => 'badArgument',
            'verb=ListRecords&metadataPrefix=marc' => 'cannotDisseminateFormat', 'verb=GetRecord&metadataPrefix=oai_dc&identifier=oai:elsewhere:1' => 'idDoesNotExist',
            'verb=ListRecords&metadataPrefix=oai_dc&from=2999-01-01' => 'noRecordsMatch', 'verb=ListRecords&metadataPrefix=oai_dc&until=yesterday' => 'badArgument',
            'verb=ListRecords&metadataPrefix=oai_dc&from=2026-01-01&until=2030-01-01T00:00:00Z' => 'badArgument', 'verb=ListRecords&resumptionToken=nonsense' => 'badResumptionToken',
            'verb=ListRecords&resumptionToken=x&metadataPrefix=oai_dc' => 'badArgument', 'verb=ListRecords&metadataPrefix=oai_dc&set=elsewhere' => 'badArgument',
        ];
        foreach ($errors as $query => $code) {
            $response = $this->oai($query);
            $this->assertSame($code, $this->text($response, '//o:error/@code'), "$query gives $code");
            if (in_array($code, ['badVerb', 'badArgument'], true)) {
                $this->assertSame(0, $response->query('//o:request/@*')->length, 'the request element names no arguments after badVerb or badArgument');
            }
        }
        $this->assertSame(2, $this->oai('verb=ListIdentifiers&metadataPrefix=oai_dc&from='.now()->subDay()->format('Y-m-d').'&until='.now()->addDay()->format('Y-m-d'))->query('//o:header')->length);
        // Publishing a later value moves the record's datestamp, so it is harvested again.
        $before = $this->text($this->oai('verb=GetRecord&metadataPrefix=oai_dc&identifier='.$this->id($this->series)), '//o:datestamp');
        $this->travel(5)->seconds();
        app(RecordService::class)->describe($this->series, ['scope-and-content' => 'Bound volumes.'], 'en');
        app(PublicationService::class)->apply($this->series, 'publish', null, false, $this->editor);
        $after = $this->text($this->oai('verb=GetRecord&metadataPrefix=oai_dc&identifier='.$this->id($this->series)), '//o:datestamp');
        $this->assertGreaterThan($before, $after);
        $this->assertSame(1, $this->oai('verb=ListIdentifiers&metadataPrefix=oai_dc&from='.$after)->query('//o:header')->length);
    }

    public function test_long_lists_come_with_a_resumption_token(): void
    {
        $records = app(RecordService::class);
        for ($i = 0; $i < 101; $i++) {
            $records->create($this->institution, self::LEVEL.'file', "File $i", 'en', $this->series, null, (string) $i);
        }
        app(PublicationService::class)->apply($this->series, 'publish', null, true, $this->editor);
        $first = $this->oai('verb=ListIdentifiers&metadataPrefix=oai_dc');
        $this->assertSame([100, '103', '0'], [$first->query('//o:header')->length, $this->text($first, '//o:resumptionToken/@completeListSize'), $this->text($first, '//o:resumptionToken/@cursor')]);
        $second = $this->oai('verb=ListIdentifiers&resumptionToken='.$this->text($first, '//o:resumptionToken'));
        $this->assertSame([3, '100', ''], [$second->query('//o:header')->length, $this->text($second, '//o:resumptionToken/@cursor'), $this->text($second, '//o:resumptionToken')]);
        $all = collect(iterator_to_array($first->query('//o:identifier')))->merge(iterator_to_array($second->query('//o:identifier')))->map->textContent;
        $this->assertSame(103, $all->unique()->count());
    }

    private function export(string $query = '', array $headers = []): TestResponse
    {
        return $this->get("/api/ric/v1/records/{$this->fonds->id}/export$query", $headers);
    }

    public function test_the_export_in_json_ld_turtle_and_rdf_xml_carries_one_triple_set(): void
    {
        $jsonld = $this->export()->assertOk()->assertHeader('Content-Type', 'application/ld+json')
            ->assertHeader('Content-Disposition', 'attachment; filename="'.$this->fonds->id.'-ric.jsonld"')->json();
        $this->assertSame([$this->fonds->iri, $this->holder->iri, $this->council->iri], array_column($jsonld['@graph'], '@id'));
        $this->assertSame(['@value' => 'Established in 1902.', '@language' => 'en'], $jsonld['@graph'][2]['rico:history']);
        $triples = fn (Graph $graph) => collect(explode("\n", trim($graph->serialise('ntriples'))))->sort()->values()->all();
        $expected = $triples(Rdf::graph($jsonld));
        $this->assertGreaterThan(15, count($expected));
        $turtle = $this->export('?format=ttl')->assertOk()->assertHeader('Content-Type', 'text/turtle; charset=utf-8')->getContent();
        $rdfxml = $this->export('?format=rdf')->assertOk()->assertHeader('Content-Type', 'application/rdf+xml; charset=utf-8')->getContent();
        foreach (['turtle' => $turtle, 'rdfxml' => $rdfxml] as $format => $body) {
            $graph = new Graph;
            $graph->parse($body, $format, $this->fonds->iri);
            $this->assertSame($expected, $triples($graph), "$format carries the same triples as the JSON-LD");
        }
        $this->assertStringContainsString('rico:RecordSet', $turtle);
        $this->export('', ['Accept' => 'text/turtle'])->assertHeader('Content-Type', 'text/turtle; charset=utf-8');
        $this->export('?format=ttl', ['Accept' => 'application/rdf+xml'])->assertHeader('Content-Type', 'text/turtle; charset=utf-8');
        $this->export('?format=pdf')->assertStatus(400)->assertJsonPath('type', 'https://openric.org/errors/bad-request');
        $this->export('', ['Accept' => 'image/png'])->assertStatus(406)->assertJsonPath('type', 'https://openric.org/errors/not-acceptable');
        $this->get('/api/ric/v1/records/no-such-slug/export')->assertNotFound()->assertHeader('Content-Type', 'application/problem+json');
        $unpublished = RecordResource::whereHas('names', fn ($q) => $q->where('value', 'Not published'))->sole();
        $this->get("/api/ric/v1/records/{$unpublished->id}/export")->assertNotFound();
    }
}
