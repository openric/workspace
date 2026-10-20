<?php

// OpenRiC Workspace - the read API: Core Discovery 0.6.0, checked against the spec's JSON Schemas (spec 0.53.0).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// tests/Fixtures/openric-0.53.0 holds the JSON Schemas of the OpenRiC
// specification 0.53.0 (https://openric.org/schemas/, CC BY 4.0).

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
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\User;
use App\Relations\RelationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private const API = '/api/ric/v1';

    private Institution $institution;

    private Repository $holder;

    private RecordResource $fonds;

    private RecordResource $series;

    private RecordResource $draft;

    private Agent $council;

    private Agent $living;

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
        app(RepositoryService::class)->describe($this->holder, ['history' => 'Founded in 1960.'], 'en');
        app(ContactPointService::class)->save($this->holder, ['label' => 'Main', 'street_address' => '1 Church Street', 'city' => 'Riverside', 'country_code' => 'ZA',
            'email' => 'archives@example.org', 'is_primary' => true]);
        $records = app(RecordService::class);
        $this->fonds = $records->create($this->institution, self::LEVEL.'fonds', 'Riverside Parish Council records', 'en', null, $this->holder, 'RPC');
        $records->describe($this->fonds, ['scope-and-content' => 'Minutes and correspondence.', 'extent' => '3 boxes', 'archivists-note' => 'Described in 2026.'], 'en');
        $records->describe($this->fonds, ['title' => 'Rekords van die Riverside-gemeenteraad'], 'af');
        app(DateService::class)->add($this->fonds, ['property' => 'creation', 'normalized' => '1902/1950-06', 'expressed' => '1902 to 1950', 'language' => 'en']);
        $this->series = $records->create($this->institution, self::LEVEL.'series', 'Minutes', 'en', $this->fonds, null, '1');
        $this->draft = $records->create($this->institution, self::LEVEL.'series', 'Unpublished correspondence', 'en', $this->fonds, null, '2');
        $agents = app(AgentService::class);
        $this->council = $agents->create($this->institution, 'corporate-body', 'Riverside Parish Council', 'en');
        $agents->describe($this->council, ['agent-history' => 'Established in 1902.'], 'en');
        $this->living = $agents->create($this->institution, 'person', 'Engelbrecht, Johanna', 'en');
        $this->living->update(['embargo_until' => now()->addYear()]);
        app(RelationService::class)->create($this->fonds, 'creation', $this->council, [], $this->editor);
        app(RelationService::class)->create($this->fonds, 'creation', $this->living, [], $this->editor);

        $publication = app(PublicationService::class);
        foreach ([$this->holder, $this->council, $this->living, $this->series] as $entity) {
            $publication->apply($entity, 'publish', null, false, $this->editor);
        }
        $publication->apply($this->fonds, 'publish', null, false, $this->editor);
        // A value changed after publishing goes back to draft and stays out of the API.
        $records->describe($this->fonds, ['accruals' => 'None expected.'], 'en');
    }

    private function valid(TestResponse $response, string $schema): array
    {
        $json = $response->getContent();
        $result = (new Validator)->validate(json_decode($json), file_get_contents(base_path("tests/Fixtures/openric-0.53.0/$schema.schema.json")));
        $this->assertTrue($result->isValid(), "valid against $schema.schema.json: ".json_encode($result->isValid() ? [] : (new ErrorFormatter)->format($result->error())));

        return json_decode($json, true);
    }

    public function test_the_service_description_health_and_vocabulary(): void
    {
        $service = $this->valid($this->get(self::API.'/')->assertOk()->assertHeader('Vary', 'Accept')->assertHeader('Access-Control-Allow-Origin', '*'), 'service-description');
        $this->assertSame(['spec_version' => '0.53.0', 'profiles' => [['id' => 'core-discovery', 'version' => '0.6.0', 'conformance' => 'full']]], $service['openric_conformance']);
        $this->assertSame(trim(file_get_contents(base_path('VERSION'))), $service['version']);
        $this->get(self::API.'/health')->assertOk()->assertExactJson(['status' => 'ok']);
        $vocabulary = $this->valid($this->get(self::API.'/vocabulary')->assertOk(), 'vocabulary');
        $this->assertContains(['@id' => 'rico:RecordSet', 'rdfs:label' => 'Record set'], $vocabulary['classes']);
        $this->assertSame('application/ld+json', $this->get(self::API.'/health', ['Accept' => 'application/ld+json'])->headers->get('Content-Type'));
        $this->assertSame('application/json', $this->getJson(self::API.'/health')->headers->get('Content-Type'));
    }

    public function test_a_record_in_ric_o_with_only_what_is_published(): void
    {
        $record = $this->valid($this->getJson(self::API."/records/{$this->fonds->id}")->assertOk(), 'record');
        $this->assertSame([$this->fonds->iri, 'rico:RecordSet', 'RA RPC'], [$record['@id'], $record['@type'], $record['rico:identifier']]);
        $this->assertSame([['@value' => 'Riverside Parish Council records', '@language' => 'en'], ['@value' => 'Rekords van die Riverside-gemeenteraad', '@language' => 'af']], $record['rico:title']);
        $this->assertSame(['@value' => 'Minutes and correspondence.', '@language' => 'en'], $record['rico:scopeAndContent']);
        $this->assertSame(['@id' => $this->fonds->iri.'#extent', '@type' => 'rico:RecordResourceExtent', 'rico:textualValue' => ['@value' => '3 boxes', '@language' => 'en']], $record['rico:hasExtent']);
        $this->assertSame(['@value' => 'Described in 2026.', '@language' => 'en'], $record['openricx:descriptiveNote']);
        $this->assertArrayNotHasKey('rico:accruals', $record, 'a draft value stays out');
        $this->assertSame(['@type' => 'openricx:DateRange', 'rico:beginningDate' => ['@value' => '1902', '@type' => 'xsd:gYear'],
            'rico:endDate' => ['@value' => '1950-06', '@type' => 'xsd:gYearMonth'], 'rico:normalizedDateValue' => '1902/1950-06',
            'rico:expressedDate' => ['@value' => '1902 to 1950', '@language' => 'en'], 'rico:hasDateType' => ['@id' => 'https://openric.org/vocab/date-type/creation']],
            $record['openricx:hasDateRangeSet'][0]);
        $this->assertSame(['@id' => $this->holder->iri, '@type' => 'rico:CorporateBody', 'rico:name' => 'Riverside Archives'], $record['rico:hasOrHadHolder']);
        $this->assertSame([['@id' => $this->council->iri, '@type' => 'rico:CorporateBody', 'rico:name' => 'Riverside Parish Council']], $record['rico:hasCreator'], 'an embargoed creator stays out');
        $this->assertSame([$this->series->iri], array_column($record['rico:includesOrIncluded'], '@id'), 'an unpublished unit inside stays out');
        foreach (['rico:hasOrHadSubject', 'rico:isOrWasSubjectOf', 'rico:hasOrHadInstantiation', 'rico:hasOrganicProvenance'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $record, "$forbidden needs a profile the service does not declare");
        }
        $this->assertSame(['@id' => $this->fonds->iri, '@type' => 'rico:RecordSet', 'rico:name' => 'Riverside Parish Council records'],
            $this->getJson(self::API."/records/{$this->series->id}")->json('rico:isOrWasIncludedIn'));
    }

    public function test_agents_and_repositories(): void
    {
        $agent = $this->valid($this->getJson(self::API."/agents/{$this->council->id}")->assertOk(), 'agent');
        $this->assertSame(['rico:CorporateBody', ['@value' => 'Riverside Parish Council', '@language' => 'en'], ['@value' => 'Established in 1902.', '@language' => 'en']],
            [$agent['@type'], $agent['rico:name'], $agent['rico:history']]);
        $repository = $this->valid($this->getJson(self::API."/repositories/{$this->holder->id}")->assertOk(), 'repository');
        $this->assertSame(['@type' => 'openricx:ContactPoint', 'openricx:streetAddress' => '1 Church Street', 'openricx:city' => 'Riverside',
            'openricx:country' => 'ZA', 'openricx:email' => 'archives@example.org'], $repository['openricx:contact']);
        $this->valid($this->getJson(self::API."/agents/{$this->living->id}")->assertNotFound()->assertHeader('Content-Type', 'application/problem+json'), 'error');
    }

    public function test_lists_page_and_count_only_what_the_public_may_read(): void
    {
        $list = $this->valid($this->getJson(self::API.'/records?limit=1')->assertOk(), 'list');
        $this->assertSame(['openricx:RecordList', 2, 1, 1], [$list['@type'], $list['openric:total'], $list['openric:page'], $list['openric:limit']]);
        $this->assertNull($list['openric:prev']);
        $this->assertStringContainsString('page=2', $list['openric:next']);
        $second = $this->getJson($list['openric:next'])->json();
        $this->assertSame([2, null], [$second['openric:page'], $second['openric:next']]);
        $this->assertNotSame($list['openric:items'][0]['@id'], $second['openric:items'][0]['@id']);
        $this->assertSame(1, $this->getJson(self::API.'/records?q=minutes')->json('openric:total'));
        $this->assertSame(1, $this->getJson(self::API.'/agents')->json('openric:total'), 'the embargoed person is not counted');
        $this->valid($this->getJson(self::API.'/repositories')->assertOk(), 'list');
        $this->getJson(self::API.'/records?limit=500')->assertStatus(400)->assertJsonPath('type', 'https://openric.org/errors/bad-request');
    }

    public function test_every_term_the_api_sends_is_defined_by_rico_or_the_extension_ontology(): void
    {
        $defined = ['rico' => array_flip(file(resource_path('ontologies/rico-1.1.terms'), FILE_IGNORE_NEW_LINES)),
            'openricx' => array_flip(file(resource_path('ontologies/openricx-ext.terms'), FILE_IGNORE_NEW_LINES))];
        $bodies = [$this->getJson(self::API.'/vocabulary')->json(), $this->getJson(self::API."/records/{$this->fonds->id}")->json(), $this->getJson(self::API."/records/{$this->series->id}")->json(),
            $this->getJson(self::API."/agents/{$this->council->id}")->json(), $this->getJson(self::API."/repositories/{$this->holder->id}")->json(), $this->getJson(self::API.'/records')->json()];
        $terms = [];
        array_walk_recursive($bodies, function ($value, $key) use (&$terms) {
            foreach ([(string) $key, is_string($value) ? $value : ''] as $candidate) {
                if (preg_match('/^(rico|openricx):(\w+)$/', $candidate, $m)) {
                    $terms[$candidate] = $m;
                }
            }
        });
        $this->assertGreaterThan(30, count($terms));
        foreach ($terms as $term => [, $prefix, $local]) {
            $this->assertArrayHasKey($local, $defined[$prefix], "$term is not defined by RiC-O 1.1 or extension ontology 1.4.0");
        }
    }

    public function test_autocomplete_errors_and_resolving_iris(): void
    {
        $hits = $this->valid($this->getJson(self::API.'/autocomplete?q=river')->assertOk(), 'autocomplete');
        $this->assertSame(['Riverside Archives', 'Riverside Parish Council', 'Riverside Parish Council records'], collect($hits)->pluck('label')->sort()->values()->all());
        $this->assertSame(['Record'], collect($this->getJson(self::API.'/autocomplete?q=river&types=record')->json())->pluck('type')->unique()->values()->all());
        $this->assertSame([], $this->valid($this->getJson(self::API.'/autocomplete?q=r')->assertOk(), 'autocomplete'), 'a one-character query finds nothing');
        $this->valid($this->getJson(self::API.'/autocomplete')->assertStatus(400), 'error');
        $problem = $this->valid($this->getJson(self::API.'/records/not-a-key')->assertNotFound(), 'error');
        $this->assertSame(['https://openric.org/errors/not-found', '/api/ric/v1/records/not-a-key'], [$problem['type'], $problem['instance']]);
        $this->valid($this->getJson(self::API.'/activities')->assertNotFound(), 'error');
        $this->getJson(self::API."/records/{$this->draft->id}")->assertNotFound();
        $this->postJson(self::API.'/records')->assertStatus(405)->assertHeader('Content-Type', 'application/problem+json');

        // The persistent IRI answers with a 303 to the API, or to the page for someone signed in.
        $this->get($this->fonds->iri, ['Accept' => 'application/ld+json'])->assertStatus(303)->assertRedirect(route('api.record', $this->fonds->id));
        $this->get($this->fonds->iri)->assertStatus(303)->assertRedirect(route('api.record', $this->fonds->id));
        $this->actingAs($this->editor)->get($this->fonds->iri, ['Accept' => 'text/html'])->assertStatus(303)->assertRedirect(route('records.show', $this->fonds));
        $this->get($this->council->iri, ['Accept' => 'application/json'])->assertRedirect(route('api.agent', $this->council->id));
    }
}
