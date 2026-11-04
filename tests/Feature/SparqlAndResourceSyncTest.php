<?php

// OpenRiC Workspace - the published RiC-O in a triple store, queried and explored, and ResourceSync (roadmap phase 16e):
// the sync puts each published entity as its own named graph and never a draft, drops what is deprecated, and in full
// what is no longer published; the endpoint forwards a query and refuses an update; Sparnatural's configuration is
// Turtle with an entity for each RiC-O class and the links between them; and ResourceSync lists what is published, what
// changed since a moment, and what was withdrawn.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Federation\SparnaturalConfig;
use App\Federation\TripleStore;
use App\Models\Deprecation;
use App\Models\Institution;
use App\Models\User;
use App\Search\SearchIndex;
use App\Vocabulary\SkosImporter;
use EasyRdf\Graph;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SparqlAndResourceSyncTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private Institution $institution;

    private User $admin;

    /** @var list<array{string, string}> what the triple store was sent: method and graph */
    private array $sent = [];

    /** The graphs the triple store says it holds. */
    private array $held = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://riverside.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->admin = app(AccountService::class)->create($this->institution, 'Admin', 'admin@riverside.example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->admin, 'administrator');
        config(['workspace.sparql.url' => 'http://fuseki.test:3030', 'workspace.sparql.dataset' => 'openric-workspace', 'workspace.sparql.user' => 'admin', 'workspace.sparql.password' => 'secret']);
        Http::fake(function (ClientRequest $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            if (str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/query')) {
                $this->sent[] = ['QUERY', (string) ($request->data()['query'] ?? '')];

                return Http::response(['head' => ['vars' => ['g']], 'results' => ['bindings' => array_map(fn ($g) => ['g' => ['type' => 'uri', 'value' => $g]], $this->held)]], 200,
                    ['Content-Type' => 'application/sparql-results+json']);
            }
            $this->assertSame('Basic '.base64_encode('admin:secret'), $request->header('Authorization')[0] ?? null);
            $this->sent[] = [$request->method(), $query['graph'] ?? '', $request->body()];

            return Http::response('', $request->method() === 'PUT' ? 201 : 204);
        });
    }

    public function test_the_store_holds_what_is_published_and_only_that(): void
    {
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $letter = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Letter of 3 May 1902', 'en', null, $holder, 'L1');
        $draft = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'A draft letter', 'en', null, $holder, 'L2');
        foreach ([$holder, $letter] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->admin);
        }
        app(SearchIndex::class)->flush();
        Deprecation::create(['institution_id' => $this->institution->id, 'subject_table' => 'record_resources', 'subject_id' => $draft->id, 'iri' => 'https://riverside.example.org/record/gone',
            'ric_class' => 'rico:Record', 'was_public' => true, 'deprecated_at' => now()]);
        $this->held = [$letter->iri, 'https://riverside.example.org/record/stale'];

        $counts = app(TripleStore::class)->sync($this->institution, true);
        $puts = collect($this->sent)->where(0, 'PUT');
        $this->assertEqualsCanonicalizing([$holder->iri, $letter->iri], $puts->pluck(1)->all(), 'a draft never');
        $this->assertStringContainsString('Letter of 3 May 1902', $puts->firstWhere(1, $letter->iri)[2]);
        $this->assertEqualsCanonicalizing(['https://riverside.example.org/record/gone', 'https://riverside.example.org/record/stale'], collect($this->sent)->where(0, 'DELETE')->pluck(1)->all());
        $this->assertSame(['put' => 2, 'dropped' => 2], $counts);

        // Next time what changed since, a quarter of an hour back, and never a draft.
        $this->sent = [];
        $this->travel(5)->minutes();
        app(RecordService::class)->describe($letter, ['scope-and-content' => 'Names the witness at the marriage.'], 'en');
        app(SearchIndex::class)->flush();
        app(TripleStore::class)->sync($this->institution);
        $since = collect($this->sent)->where(0, 'PUT')->pluck(1);
        $this->assertContains($letter->iri, $since);
        $this->assertNotContains($draft->iri, $since);
        $this->artisan('workspace:sparql:sync')->expectsOutputToContain('Riverside Archives:')->assertSuccessful();
    }

    public function test_the_endpoint_forwards_a_query_and_refuses_an_update(): void
    {
        $this->held = ['https://riverside.example.org/record/one'];
        $this->post('/sparql', ['query' => "PREFIX rico: <https://www.ica.org/standards/RiC/ontology#>\nSELECT ?g WHERE { GRAPH ?g { ?s a rico:Record } }"])
            ->assertOk()->assertHeader('Access-Control-Allow-Origin', '*')->assertJsonPath('results.bindings.0.g.value', 'https://riverside.example.org/record/one');
        $this->get('/sparql?query='.rawurlencode('ASK { ?s ?p ?o }'))->assertOk();
        $this->post('/sparql', ['query' => 'INSERT DATA { <a:b> <a:c> <a:d> }'])->assertStatus(400);
        $this->post('/sparql', ['query' => 'SELECT * WHERE { ?s ?p ?o } ; DROP ALL'])->assertStatus(400);
        $this->post('/sparql', ['query' => 'DELETE WHERE { ?s ?p ?o }'])->assertStatus(400);
        $this->get('/sparql')->assertStatus(400);
        $this->assertCount(2, collect($this->sent)->where(0, 'QUERY'), 'an update never reaches the store');

        $this->get('/discover/explore')->assertOk()->assertSee('<spar-natural', false)->assertSee(route('sparnatural.config'), false);
        $this->get(route('discover.home'))->assertSee(route('discover.explore'), false);
        config(['workspace.sparql.url' => '']);
        $this->post('/sparql', ['query' => 'ASK { ?s ?p ?o }'])->assertStatus(503);
        $this->get('/discover/explore')->assertOk()->assertDontSee('<spar-natural', false);
        $this->get(route('discover.home'))->assertDontSee(route('discover.explore'), false);
    }

    public function test_sparnatural_is_configured_from_the_shapes_and_the_relation_types(): void
    {
        $turtle = $this->get('/sparnatural/config.ttl')->assertOk()->assertHeader('Content-Type', 'text/turtle; charset=utf-8')->getContent();
        $graph = new Graph;
        $graph->parse($turtle, 'turtle');
        $entities = collect($graph->allOfType('http://www.w3.org/ns/shacl#NodeShape'))->mapWithKeys(fn ($s) => [$s->getResource('sh:targetClass')->getUri() => $s]);
        foreach (['Record', 'RecordSet', 'Person', 'CorporateBody', 'Place', 'Activity'] as $class) {
            $this->assertTrue($entities->has('https://www.ica.org/standards/RiC/ontology#'.$class), $class);
        }
        $record = $entities['https://www.ica.org/standards/RiC/ontology#Record'];
        $properties = collect($record->allResources('sh:property'));
        $this->assertTrue($properties->contains(fn ($p) => $p->getResource('dash:propertyRole')?->getUri() === 'http://datashapes.org/dash#LabelRole'), 'a record is named by its title');
        $this->assertTrue($properties->contains(fn ($p) => str_ends_with((string) $p->getResource('sh:node')?->getUri(), '#Person')), 'a record links to a person');
        $this->assertSame($turtle, app(SparnaturalConfig::class)->turtle(), 'cached');
    }

    public function test_resourcesync_lists_what_is_published_changed_and_withdrawn(): void
    {
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $letter = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Letter of 3 May 1902', 'en', null, $holder, 'L1');
        $draft = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'A draft letter', 'en', null, $holder, 'L2');
        foreach ([$holder, $letter] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->admin);
        }
        Deprecation::create(['institution_id' => $this->institution->id, 'subject_table' => 'record_resources', 'subject_id' => $draft->id, 'iri' => 'https://riverside.example.org/record/gone',
            'ric_class' => 'rico:Record', 'was_public' => true, 'deprecated_at' => now()]);

        $this->get('/.well-known/resourcesync')->assertOk()->assertSee('capability="description"', false)->assertSee(route('resourcesync.capabilities'), false);
        $this->get('/resourcesync/capabilitylist.xml')->assertOk()->assertSee('capability="resourcelist"', false)->assertSee(route('resourcesync.changes'), false);
        $list = simplexml_load_string($this->get('/resourcesync/resourcelist.xml')->assertOk()->getContent());
        $locs = array_map('strval', $list->xpath('//*[local-name()="loc"]'));
        $this->assertEqualsCanonicalizing([$holder->iri, $letter->iri], $locs, 'a draft never');

        $changes = $this->get('/resourcesync/changelist.xml?from='.rawurlencode(now()->subHour()->toAtomString()))->assertOk()->getContent();
        $this->assertStringContainsString('<loc>'.$letter->iri.'</loc>', $changes);
        $this->assertStringContainsString('<loc>https://riverside.example.org/record/gone</loc>', $changes);
        $this->assertStringContainsString('change="deleted"', $changes);
        $this->assertStringNotContainsString('<loc>'.$letter->iri.'</loc>', $this->get('/resourcesync/changelist.xml?from='.rawurlencode(now()->addHour()->toAtomString()))->getContent());
        $this->get('/resourcesync/changelist.xml?from=yesterday-ish')->assertStatus(400);
        $this->assertStringContainsString('<loc>https://riverside.example.org/record/gone</loc>', $this->get('/resourcesync/changelist.xml')->getContent(), 'thirty days back unless asked');
        config(['workspace.resourcesync.page_size' => 1]);
        $first = $this->get('/resourcesync/resourcelist.xml')->getContent();
        $this->assertSame(1, substr_count($first, '<url>'));
        $this->assertStringContainsString('rel="next" href="'.e(route('resourcesync.resources', ['page' => 2])).'"', $first);
        $second = $this->get('/resourcesync/resourcelist.xml?page=2')->getContent();
        $this->assertStringContainsString('rel="prev"', $second);
        $this->assertStringNotContainsString('rel="next"', $second);
    }
}
