<?php

// OpenRiC Workspace - the Authority & Context (0.4.0) and Graph Traversal (0.6.0) profiles (roadmap phase 23b): places,
// rules and activities one by one and as lists, the subgraph, every relation, the relations of one entity and the
// hierarchy, each valid against the specification's JSON Schemas; relations to what the public may not read left out.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// tests/Fixtures/openric-0.56.0 holds the JSON Schemas of the OpenRiC specification 0.56.0 (CC BY 4.0). The
// relation-list, relations-for and hierarchy schemas give ids as integers; the Workspace's ids are UUIDs, so those
// three are read here with string ids allowed (conformance/README.md, departures).

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\ActivityService;
use App\Description\AgentService;
use App\Description\DateService;
use App\Description\InstantiationService;
use App\Description\PlaceService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Description\RuleService;
use App\Http\Controllers\Api\DiscoveryController;
use App\Models\Activity;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\Rule;
use App\Models\User;
use App\Relations\RelationService;
use App\Support\Vocab;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Tests\TestCase;

class GraphProfilesTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://archives.example.org/api/ric/v1';

    private array $e = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        foreach (['role-type', 'rule-type', 'activity-type'] as $fixture) {
            app(SkosImporter::class)->import(file_get_contents(base_path("tests/Fixtures/$fixture.ttl")));
        }
        $institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($institution);
        $editor = app(AccountService::class)->create($institution, 'Editor', 'editor@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($editor, 'editor');
        $records = app(RecordService::class);
        $places = app(PlaceService::class);
        $relations = app(RelationService::class);
        $e = ['holder' => app(RepositoryService::class)->create($institution, 'Riverside Archives', 'en', 'RA')];
        $e['fonds'] = $records->create($institution, Vocab::BASE.'level-of-description/fonds', 'Riverside Town Council', 'en', null, $e['holder'], 'RTC');
        $e['minutes'] = $records->create($institution, Vocab::BASE.'level-of-description/series', 'Council minutes', 'en', $e['fonds'], null, '1');
        $e['letters'] = $records->create($institution, Vocab::BASE.'level-of-description/series', 'Letters received', 'en', $e['fonds'], null, '2');
        $e['draft'] = $records->create($institution, Vocab::BASE.'level-of-description/series', 'Unpublished rates registers', 'en', $e['fonds'], null, '3');
        $e['council'] = app(AgentService::class)->create($institution, 'corporate-body', 'Riverside Town Council', 'en');
        $e['province'] = $places->create($institution, 'Cape Province', 'en');
        $e['town'] = $places->create($institution, 'Riverside', 'en', $e['province']);
        $e['ordinance'] = app(RuleService::class)->create($institution, 'Local Government Ordinance 17 of 1939', 'en', Concept::where('iri', Rule::TYPES.'mandate')->value('id'));
        $e['meetings'] = app(ActivityService::class)->create($institution, 'Council meetings', 'en', Concept::where('iri', Activity::TYPES.'production')->value('id'));
        app(DateService::class)->add($e['meetings'], ['property' => 'beginning', 'normalized' => '1939']);
        $mandate = $relations->create($e['ordinance'], 'mandate', $e['council'], ['certainty' => 'certain'], $editor);
        app(DateService::class)->add($mandate, ['property' => 'beginning', 'normalized' => '1939-07-01']);
        $relations->addEvidence($mandate, $e['minutes'], $editor);
        $relations->create($e['meetings'], 'performance', $e['council'], [], $editor);
        $relations->create($e['meetings'], 'result', $e['minutes'], [], $editor);
        $relations->create($e['town'], 'place', $e['meetings'], [], $editor);
        $relations->create($e['ordinance'], 'regulation', $e['draft'], [], $editor);
        foreach ($e as $key => $entity) {
            if ($key !== 'draft') {
                app(PublicationService::class)->apply($entity, 'publish', null, false, $editor);
            }
        }
        $this->e = $e;
    }

    /** The response, valid against the schema; $uuids lets ids be strings, as the Workspace's are. */
    private function valid(TestResponse $response, string $schema, bool $uuids = false): array
    {
        $text = file_get_contents(base_path("tests/Fixtures/openric-0.56.0/$schema.schema.json"));
        if ($uuids) {
            $text = preg_replace('/("(?:id|subject_id|object_id|entity_id|target_id)"\s*:\s*\{[^{}]*?"type"\s*:\s*)"integer"/', '$1["integer", "string"]', $text);
        }
        $result = (new Validator)->validate(json_decode($response->getContent()), $text);
        $this->assertTrue($result->isValid(), "valid against $schema.schema.json: ".json_encode($result->isValid() ? [] : (new ErrorFormatter)->format($result->error())));

        return $response->json();
    }

    public function test_places_rules_and_activities_are_served_as_the_authority_and_context_profile_shapes_them(): void
    {
        $service = $this->getJson(self::API)->assertOk()->json('openric_conformance.profiles');
        $this->assertSame(DiscoveryController::PROFILES, $service);
        $this->assertContains('authority-context', array_column($service, 'id'));

        $place = $this->valid($this->getJson(self::API.'/places/'.$this->e['town']->id)->assertOk(), 'place');
        $this->assertSame(['@id' => $this->e['province']->iri, '@type' => 'rico:Place', 'rico:name' => ['@value' => 'Cape Province', '@language' => 'en']], $place['rico:isOrWasPartOf']);
        $this->assertSame([['@id' => $this->e['meetings']->iri]], $place['rico:isPlaceAssociatedWith']);

        $rule = $this->valid($this->getJson(self::API.'/rules/'.$this->e['ordinance']->id)->assertOk(), 'rule');
        $this->assertSame(['rico:Rule', 'rico:Mandate'], $rule['@type'], 'a mandate is typed both, so the Rule shape still applies');
        $this->assertSame(Rule::TYPES.'mandate', $rule['rico:hasOrHadRuleType']);
        $this->assertSame([['@id' => $this->e['council']->iri]], $rule['rico:authorizes']);
        $this->assertArrayNotHasKey('rico:regulatesOrRegulated', $rule, 'the unpublished series it regulates is left out');

        $activity = $this->valid($this->getJson(self::API.'/activities/'.$this->e['meetings']->id)->assertOk(), 'activity');
        $this->assertSame(['@id' => Activity::TYPES.'production'], $activity['rico:hasActivityType']);
        // What it resulted in and who took part are stubs with their class (Provenance & Event); the performer takes part too.
        $this->assertSame([[$this->e['minutes']->iri, 'rico:RecordSet']], array_map(fn ($s) => [$s['@id'], $s['@type']], $activity['rico:resultsOrResultedIn']));
        $this->assertSame([$this->e['council']->iri], array_column($activity['rico:isOrWasPerformedBy'], '@id'));
        $this->assertSame([$this->e['council']->iri], array_column($activity['rico:hasOrHadParticipant'], '@id'));
        $this->assertSame('1939', $activity['rico:isAssociatedWithDate'][0]['rico:beginningDate']['@value']);

        foreach (['places' => 2, 'rules' => 1, 'activities' => 1] as $list => $total) {
            $body = $this->valid($this->getJson(self::API."/$list")->assertOk(), 'list');
            $this->assertSame($total, $body['openric:total']);
            $this->assertArrayHasKey('rico:name', $body['openric:items'][0]);
        }
        // The agent now names its mandate, from the other end.
        $this->assertSame([['@id' => $this->e['ordinance']->iri]], $this->getJson(self::API.'/agents/'.$this->e['council']->id)->json('rico:authorizedBy'));
        // An IRI asked for as JSON-LD goes to its API description.
        $this->get('https://archives.example.org/rule/'.$this->e['ordinance']->id, ['Accept' => 'application/ld+json'])->assertRedirect(route('api.rule', $this->e['ordinance']->id));
        $this->getJson(self::API.'/rules/'.$this->e['draft']->id)->assertNotFound();
    }

    public function test_the_graph_relations_and_hierarchy_are_served_as_the_graph_traversal_profile_shapes_them(): void
    {
        $graph = $this->valid($this->getJson(self::API.'/graph?uri='.urlencode($this->e['council']->iri).'&depth=2')->assertOk(), 'subgraph');
        $this->assertSame($this->e['council']->iri, $graph['openric:root']);
        $edges = collect($graph['openric:edges'])->map(fn ($e) => [$e['source'], $e['predicate'], $e['target']]);
        $this->assertContains([$this->e['ordinance']->iri, 'rico:authorizes', $this->e['council']->iri], $edges->all());
        $this->assertContains([$this->e['meetings']->iri, 'rico:isOrWasPerformedBy', $this->e['council']->iri], $edges->all());
        $this->assertNotContains($this->e['draft']->iri, array_column($graph['openric:nodes'], 'id'));
        $this->getJson(self::API.'/graph?uri='.urlencode($this->e['council']->iri).'&depth=4')->assertStatus(400);
        $this->getJson(self::API.'/graph?uri=https://archives.example.org/thing/'.$this->e['council']->id)->assertStatus(400);

        $list = $this->valid($this->getJson(self::API.'/relations?limit=2')->assertOk(), 'relation-list', true);
        $this->assertSame(['page' => 1, 'per_page' => 2, 'total' => 4, 'last_page' => 2], $list['pagination'], 'the regulation of the unpublished series is left out');
        $mandate = collect($this->getJson(self::API.'/relations?limit=10')->json('data'))->firstWhere('rico_predicate', 'rico:authorizes');
        $this->assertSame(['Mandate', 'CorporateBody', '1939-07-01', 'certain', 'Council minutes'], [$mandate['domain_class'], $mandate['range_class'], $mandate['start_date'], $mandate['certainty'], $mandate['evidence']]);

        $for = $this->valid($this->getJson(self::API.'/relations-for/'.$this->e['council']->id)->assertOk(), 'relations-for', true);
        $this->assertSame([2, []], [$for['total'], $for['outgoing']]);
        $this->assertEqualsCanonicalizing(['rico:authorizedBy', 'rico:performsOrPerformed'], array_column($for['incoming'], 'rico_predicate'));

        $tree = $this->valid($this->getJson(self::API.'/hierarchy/'.$this->e['minutes']->id)->assertOk(), 'hierarchy', true);
        $this->assertSame([$this->e['fonds']->id, [], ['Letters received']], [$tree['parent']['id'], $tree['children'], array_column($tree['siblings'], 'name')]);
        $places = $this->valid($this->getJson(self::API.'/hierarchy/'.$this->e['province']->id)->assertOk(), 'hierarchy', true);
        $this->assertSame([null, ['Riverside'], 'Place'], [$places['parent'], array_column($places['children'], 'name'), $places['class']]);
        $this->getJson(self::API.'/hierarchy/'.$this->e['draft']->id)->assertNotFound();
    }

    public function test_instantiations_and_functions_are_served_as_the_digital_object_linkage_profile_shapes_them(): void
    {
        $image = new \Imagick;
        $image->newImage(60, 40, new \ImagickPixel('#d8c9a3'));
        $image->setImageFormat('png');
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($path, $image->getImageBlob());
        config(['workspace.object_store' => sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6))]);
        $editor = User::where('email', 'editor@example.org')->sole();
        $instantiations = app(InstantiationService::class);
        $scan = $instantiations->upload($this->e['minutes'], $path, 'minutes-1939.png', 'en', 'Scan of the minutes', null, 'online', $editor);
        $paper = $instantiations->create($this->e['minutes'], 'The minute book on paper', 'en', 'paper', 'on-request', $editor);
        app(RelationService::class)->create($paper, 'derivation', $scan, [], $editor);
        app(PublicationService::class)->apply($this->e['minutes'], 'publish', null, false, $editor);
        app(ActivityService::class)->updateFunction($this->e['meetings'], true, $editor);

        $doc = $this->valid($this->getJson(self::API.'/instantiations/'.$scan->id)->assertOk(), 'instantiation');
        $this->assertSame(['minutes-1939.png', 'image/png', 'bytes'], [$doc['rico:identifier'], $doc['openricx:hasMimeType'], $doc['rico:hasExtent']['rico:hasUnitOfMeasurement']['rico:name']]);
        $this->assertStringStartsWith('Checksum (sha256): ', $doc['openricx:technicalCharacteristics']);
        $this->assertSame($this->e['minutes']->iri, $doc['rico:isOrWasInstantiationOf']['@id']);
        $this->assertSame([['@id' => $paper->iri]], $doc['rico:isOrWasDerivedFromInstantiation']);
        $this->assertSame(Vocab::BASE.'carrier-type/paper', $this->getJson(self::API.'/instantiations/'.$paper->id)->json('rico:hasCarrierType'));
        $this->assertEqualsCanonicalizing([['@id' => $scan->iri], ['@id' => $paper->iri]], $this->getJson(self::API.'/records/'.$this->e['minutes']->id)->json('rico:hasOrHadInstantiation'));
        $this->assertSame(2, $this->valid($this->getJson(self::API.'/instantiations')->assertOk(), 'list')['openric:total']);

        // A function is served as one, and no longer as a dated event.
        $function = $this->getJson(self::API.'/functions/'.$this->e['meetings']->id)->assertOk()->json();
        $this->assertSame(['openricx:Function', 'Council meetings'], [$function['@type'], $function['rico:name'][0]['@value'] ?? $function['rico:name']['@value']]);
        $this->assertSame(1, $this->valid($this->getJson(self::API.'/functions')->assertOk(), 'list')['openric:total']);
        $this->getJson(self::API.'/activities/'.$this->e['meetings']->id)->assertNotFound();
        $this->assertSame(0, $this->getJson(self::API.'/activities')->json('openric:total'));
    }
}
