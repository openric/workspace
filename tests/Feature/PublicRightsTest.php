<?php

// OpenRiC Workspace - rights and Local Contexts Labels in the public catalogue, the API and the exports (WS-007, roadmap
// phase 2, ADR 0008): only rights in force, a holder only when it may be read, statements, licences and Labels by
// their publishers' IRIs.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\AgentService;
use App\Description\InstantiationService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use App\Rights\CommunityLabelService;
use App\Rights\RightsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Opis\JsonSchema\Validator;
use Tests\TestCase;

class PublicRightsTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://archives.example.org';

    private string $store;

    private User $editor;

    private RecordResource $record;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        $this->loadBundledVocabularies();
        $institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => self::SITE]);
        app(RoleService::class)->seedDefaults($institution);
        $this->editor = app(AccountService::class)->create($institution, 'Editor', 'editor@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->editor, 'editor');
        $holder = app(RepositoryService::class)->create($institution, 'Riverside Archives', 'en', 'RA');
        $this->record = app(RecordService::class)->create($institution, 'https://workspace.openric.org/vocab/level-of-description/item', 'Photograph of the council', 'en', null, $holder, 'P1');
        $image = new \Imagick;
        $image->newImage(1200, 900, new \ImagickPixel('#d8c9a3'));
        $image->setImageFormat('png');
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($path, $image->getImageBlob());
        $scan = app(InstantiationService::class)->upload($this->record, $path, 'photograph.png', 'en', 'Scan of the photograph', null, 'online', $this->editor);

        $society = app(AgentService::class)->create($institution, 'corporate-body', 'Riverside Photographic Society', 'en');
        $private = app(AgentService::class)->create($institution, 'person', 'Botha, Maria', 'en');
        $rights = app(RightsService::class);
        $rights->add($this->record, ['basis' => 'copyright', 'statement' => 'InC-EDU', 'holder' => $society->id], $this->editor);
        $rights->add($scan, ['basis' => 'licence', 'licence' => 'CC-BY-NC-4.0', 'holder' => $private->id, 'grants' => [['act' => 'disseminate', 'restriction' => 'allow']]], $this->editor);
        // Ended: not shown anywhere.
        $rights->add($this->record, ['basis' => 'donor', 'statement' => 'NoC-CR', 'ends_on' => '2020-12-31'], $this->editor);
        app(CommunityLabelService::class)->apply($this->record, ['label' => 'tk_a', 'applied_by' => 'community'], $this->editor);

        foreach ([$holder, $this->record, $scan, $society] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->editor);
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->store)) {
            exec('rm -rf '.escapeshellarg($this->store));
        }
        parent::tearDown();
    }

    public function test_the_public_catalogue_shows_rights_in_force_and_labels(): void
    {
        $page = $this->get(self::SITE."/discover/records/{$this->record->id}")->assertOk();
        $page->assertSee('In Copyright - Educational Use Permitted')->assertSee('http://rightsstatements.org/vocab/InC-EDU/1.0/', false)
            ->assertSee('Riverside Photographic Society')->assertSee('TK Attribution')->assertSee('CC-BY-NC-4.0')
            ->assertDontSee('No Copyright - Contractual Restrictions');
        // The person holding the scan's right is not published: not named.
        $page->assertDontSee('Botha, Maria');
    }

    public function test_the_api_says_the_same_as_rules_by_their_publishers_iris(): void
    {
        $json = $this->getJson(self::SITE."/api/ric/v1/records/{$this->record->id}")->assertOk();
        $schema = file_get_contents(base_path('tests/Fixtures/openric-0.56.0/record.schema.json'));
        $this->assertTrue((new Validator)->validate(json_decode($json->getContent()), $schema)->isValid());
        $rules = $json->json('rico:isOrWasRegulatedBy');
        $this->assertCount(2, $rules);
        $this->assertSame(['rico:Rule', 'https://workspace.openric.org/vocab/rights-basis/copyright', 'http://rightsstatements.org/vocab/InC-EDU/1.0/'],
            [$rules[0]['@type'], $rules[0]['rico:hasOrHadRuleType']['@id'], $rules[0]['rico:isAssociatedWithRule'][0]['@id']]);
        $this->assertSame('https://localcontexts.org/label/tk-attribution/', $rules[1]['@id']);
        $this->assertSame('Riverside Photographic Society', $json->json('rico:hasOrHadIntellectualPropertyRightsHolder.0.rico:name'));
        $this->assertStringContainsString('<http://rightsstatements.org/vocab/InC-EDU/1.0/>', $this->get(self::SITE."/api/ric/v1/records/{$this->record->id}/export?format=ttl")->getContent());

        $files = $this->getJson(self::SITE."/api/ric/v1/records/{$this->record->id}/files")->assertOk();
        $this->assertSame('InC-EDU', $files->json('rights.rules.0.statement.code'));
        $this->assertSame('tk_a', $files->json('rights.labels.0.code'));
        $scanRights = $files->json('instantiations.0.rights.rules.0');
        $this->assertSame(['CC-BY-NC-4.0', null, 'allow'], [$scanRights['licence']['code'], $scanRights['holder'], $scanRights['grants']['disseminate']['restriction']]);
    }

    public function test_the_exports_carry_the_statements_and_licences(): void
    {
        $base = "/records/{$this->record->id}/export";
        $ead = $this->actingAs($this->editor)->get(self::SITE."$base/ead3")->assertOk()->getContent();
        $this->assertStringContainsString('<userestrict encodinganalog="3.4.2">', $ead);
        $this->assertStringContainsString('href="http://rightsstatements.org/vocab/InC-EDU/1.0/"', $ead);
        $this->assertStringContainsString('href="https://localcontexts.org/label/tk-attribution/"', $ead);
        $this->assertStringNotContainsString('NoC-CR', $ead);
        $this->assertStringContainsString('<dc:rights>http://rightsstatements.org/vocab/InC-EDU/1.0/</dc:rights>', $this->actingAs($this->editor)->get(self::SITE."$base/dc")->getContent());
        $this->assertStringContainsString('xlink:href="http://rightsstatements.org/vocab/InC-EDU/1.0/"', $this->actingAs($this->editor)->get(self::SITE."$base/mods")->getContent());
    }
}
