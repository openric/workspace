<?php

// OpenRiC Workspace - SHACL is the gate, not a guideline (Governance profile 0.45.0, section 5; roadmap phase 11b,
// ADR 0013): every kind of RiC-O the API publishes, and a deprecated entity, conforms to the pinned OpenRiC shapes.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// tests/Fixtures/openric-0.56.0/shapes holds the SHACL shapes of the OpenRiC specification 0.56.0 (CC BY 4.0).
// pySHACL checks them (PYSHACL, else pyshacl on the path). CI installs it and sets SHACL_REQUIRED, so there a missing
// validator fails the build; elsewhere the test is skipped and says why.

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Api\JsonLd;
use App\Description\ActivityService;
use App\Description\AgentService;
use App\Description\ContactPointService;
use App\Description\DateService;
use App\Description\InstantiationService;
use App\Description\PlaceService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Description\RuleService;
use App\Models\Activity;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\Rule;
use App\Relations\RelationService;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class ShaclGateTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private const API = '/api/ric/v1';

    private const SHAPES = ['openric', 'always-on', 'core-discovery', 'export-only', 'authority-context', 'digital-object-linkage', 'provenance-event'];

    public function test_every_kind_of_published_ric_o_conforms_to_the_pinned_shapes(): void
    {
        $validator = (string) (getenv('PYSHACL') ?: 'pyshacl');
        if (! Process::run([$validator, '--version'])->successful()) {
            getenv('SHACL_REQUIRED') ? $this->fail("pySHACL ($validator) is not installed, and CI requires it") : $this->markTestSkipped("pySHACL ($validator) is not installed");
        }

        $this->loadBundledVocabularies();
        foreach (['rule-type', 'activity-type'] as $fixture) {
            app(SkosImporter::class)->import(file_get_contents(base_path("tests/Fixtures/$fixture.ttl")));
        }
        $institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($institution);
        $editor = app(AccountService::class)->create($institution, 'Editor', 'editor@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($editor, 'editor');
        $holder = app(RepositoryService::class)->create($institution, 'Riverside Archives', 'en', 'RA');
        app(RepositoryService::class)->describe($holder, ['history' => 'Founded in 1960.'], 'en');
        app(ContactPointService::class)->save($holder, ['label' => 'Main', 'city' => 'Riverside', 'country_code' => 'ZA', 'is_primary' => true]);
        $records = app(RecordService::class);
        $fonds = $records->create($institution, self::LEVEL.'fonds', 'Riverside Parish Council records', 'en', null, $holder, 'RPC');
        $records->describe($fonds, ['scope-and-content' => 'Minutes.', 'extent' => '3 boxes'], 'en');
        app(DateService::class)->add($fonds, ['property' => 'creation', 'normalized' => '1902/1950-06', 'expressed' => '1902 to 1950', 'language' => 'en']);
        $item = $records->create($institution, self::LEVEL.'item', 'Minute book', 'en', $fonds, null, '1');
        $council = app(AgentService::class)->create($institution, 'corporate-body', 'Riverside Parish Council', 'en');
        $person = app(AgentService::class)->create($institution, 'person', 'Engelbrecht, Johanna', 'en');
        app(DateService::class)->add($person, ['property' => 'birth', 'normalized' => '1871']);
        app(RelationService::class)->create($fonds, 'creation', $council, [], $editor);
        // Authority & Context and Graph Traversal (roadmap phase 23b): a place, a mandate and an activity, related.
        $town = app(PlaceService::class)->create($institution, 'Riverside', 'en');
        $mandate = app(RuleService::class)->create($institution, 'Local Government Ordinance 17 of 1939', 'en', Concept::where('iri', Rule::TYPES.'mandate')->value('id'));
        app(RuleService::class)->describe($mandate, ['rule-description' => 'Section 12: the town clerk keeps the minutes.'], 'en');
        $meetings = app(ActivityService::class)->create($institution, 'Council meetings', 'en', Concept::where('iri', Activity::TYPES.'production')->value('id'));
        app(DateService::class)->add($meetings, ['property' => 'beginning', 'normalized' => '1902']);
        $relations = app(RelationService::class);
        $relations->create($mandate, 'mandate', $council, [], $editor);
        $relations->create($mandate, 'regulation', $fonds, [], $editor);
        $relations->create($meetings, 'performance', $council, [], $editor);
        $relations->create($meetings, 'result', $fonds, [], $editor);
        $relations->create($town, 'place', $meetings, [], $editor);
        // Digital Object Linkage (roadmap phase 24): a carrier on paper, and a function.
        $paper = app(InstantiationService::class)->create($item, 'The minute book on paper', 'en', 'paper', 'on-request', $editor);
        $keeping = app(ActivityService::class)->create($institution, 'Keeping the council minutes', 'en');
        app(ActivityService::class)->updateFunction($keeping, true, $editor);
        $gone = $records->create($institution, self::LEVEL.'fonds', 'Withdrawn fonds', 'en', null, $holder, 'W');
        foreach ([$holder, $council, $person, $fonds, $item, $gone, $town, $mandate, $meetings, $keeping] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $editor);
        }
        $this->actingAs($editor)->post("/records/{$gone->id}/delete", ['reason' => 'Withdrawn.', 'replaced_by' => $fonds->iri])->assertRedirect();
        auth()->logout();

        $documents = [
            'record' => $this->get(self::API."/records/{$fonds->id}")->assertOk()->getContent(),
            'item' => $this->get(self::API."/records/{$item->id}")->assertOk()->getContent(),
            'export' => $this->get(self::API."/records/{$fonds->id}/export?format=jsonld")->assertOk()->getContent(),
            'corporate body' => $this->get(self::API."/agents/{$council->id}")->assertOk()->getContent(),
            'person' => $this->get(self::API."/agents/{$person->id}")->assertOk()->getContent(),
            'repository' => $this->get(self::API."/repositories/{$holder->id}")->assertOk()->getContent(),
            'deprecated' => $this->get(self::API."/records/{$gone->id}")->assertStatus(410)->getContent(),
            'place' => $this->get(self::API."/places/{$town->id}")->assertOk()->getContent(),
            'mandate' => $this->get(self::API."/rules/{$mandate->id}")->assertOk()->getContent(),
            'activity' => $this->get(self::API."/activities/{$meetings->id}")->assertOk()->getContent(),
            'instantiation' => $this->get(self::API."/instantiations/{$paper->id}")->assertOk()->getContent(),
            'function' => $this->get(self::API."/functions/{$keeping->id}")->assertOk()->getContent(),
        ];
        foreach ($documents as $what => $json) {
            $data = tempnam(sys_get_temp_dir(), 'shacl').'.jsonld';
            file_put_contents($data, $json);
            foreach (self::SHAPES as $shapes) {
                // A warning is advice (sh:Warning); a violation fails the gate.
                $result = Process::run([$validator, '--allow-warnings', '-s', base_path("tests/Fixtures/openric-0.56.0/shapes/$shapes.shacl.ttl"), '-df', 'json-ld', '-sf', 'turtle', $data]);
                $this->assertTrue($result->successful(), "the $what conforms to $shapes.shacl.ttl:\n".mb_substr($result->output().$result->errorOutput(), 0, 3000));
            }
            unlink($data);
        }

        // Graph Traversal's shapes assume the whole graph (profile 5): every published document together. Its
        // full-graph SPARQL checks are Warning or Info by the profile, but declare that inside the constraint, where
        // SHACL does not read it, so pySHACL reports them as violations; only the type checks the profile makes
        // violations fail the gate here (conformance/README.md, departures).
        $graph = ['@context' => JsonLd::CONTEXT, '@graph' => collect($documents)->except('deprecated')->map(fn ($json) => json_decode($json, true))
            ->map(fn ($d) => collect($d)->except('@context')->all())->values()->all()];
        $data = tempnam(sys_get_temp_dir(), 'shacl').'.jsonld';
        file_put_contents($data, json_encode($graph));
        $result = Process::run([$validator, '--allow-warnings', '-s', base_path('tests/Fixtures/openric-0.56.0/shapes/graph-traversal.shacl.ttl'), '-df', 'json-ld', '-sf', 'turtle', $data]);
        unlink($data);
        preg_match_all('/Source Shape: :(\w+)/', $result->output(), $shapes);
        $this->assertSame([], array_values(array_diff(array_unique($shapes[1]), ['OrphanedRecordShape', 'UnlinkedAgentShape', 'InstantiationLinkedFromRecordShape', 'DuplicateIdentifierShape', 'RelationConsistencyShape'])),
            "the published graph conforms to graph-traversal.shacl.ttl:\n".mb_substr($result->output(), 0, 3000));
    }
}
