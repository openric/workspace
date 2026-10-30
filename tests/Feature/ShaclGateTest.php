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
use App\Description\AgentService;
use App\Description\ContactPointService;
use App\Description\DateService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Institution;
use App\Relations\RelationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class ShaclGateTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private const API = '/api/ric/v1';

    private const SHAPES = ['openric', 'always-on', 'core-discovery', 'export-only'];

    public function test_every_kind_of_published_ric_o_conforms_to_the_pinned_shapes(): void
    {
        $validator = (string) (getenv('PYSHACL') ?: 'pyshacl');
        if (! Process::run([$validator, '--version'])->successful()) {
            getenv('SHACL_REQUIRED') ? $this->fail("pySHACL ($validator) is not installed, and CI requires it") : $this->markTestSkipped("pySHACL ($validator) is not installed");
        }

        $this->loadBundledVocabularies();
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
        $gone = $records->create($institution, self::LEVEL.'fonds', 'Withdrawn fonds', 'en', null, $holder, 'W');
        foreach ([$holder, $council, $person, $fonds, $item, $gone] as $entity) {
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
    }
}
