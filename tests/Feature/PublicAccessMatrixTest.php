<?php

// OpenRiC Workspace - the public side of the access matrix (roadmap phase 23): what a visitor, another institution's
// catalogue and API keys that are revoked, expired, of another institution or without the scope must not reach, in the
// catalogue, the API, OAI-PMH, IIIF, citations, file delivery and the lists and searches that could name it.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Accounts\ApiKeyService;
use App\Description\InstantiationService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\DigitalFile;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use App\Rights\Embargoes;
use App\Search\SearchIndex;
use App\Support\Vocab;
use App\Vocabulary\SkosImporter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Imagick;
use ImagickPixel;
use Tests\TestCase;

class PublicAccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://archives.example.org';

    private const OTHER = 'https://hills.example.org';

    private const LEVEL = Vocab::BASE.'level-of-description/';

    private string $store;

    private User $admin;

    private RecordResource $letter;

    private RecordResource $draft;

    private RecordResource $embargoed;

    /** @var array<string, DigitalFile> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        Mail::fake();
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $river = Institution::create(['name' => 'Riverside Archives', 'base_iri' => self::SITE]);
        app(RoleService::class)->seedDefaults($river);
        $this->admin = app(AccountService::class)->create($river, 'Admin', 'admin@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->admin, 'administrator');
        $records = app(RecordService::class);
        $holder = app(RepositoryService::class)->create($river, 'Riverside Archives', 'en', 'RA');
        $fonds = $records->create($river, self::LEVEL.'fonds', 'Parish council records', 'en', null, $holder, 'PC');
        $this->letter = $records->create($river, self::LEVEL.'item', 'Letter about the parish bridge', 'en', $fonds, null, '1');
        $this->draft = $records->create($river, self::LEVEL.'item', 'Unpublished minutes of the sewage committee', 'en', $fonds, null, '2');
        $this->embargoed = $records->create($river, self::LEVEL.'item', 'Embargoed valuation roll of the farms', 'en', $fonds, null, '3');
        foreach ([$holder, $fonds, $this->letter, $this->embargoed] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->admin);
        }
        foreach (['letter', 'draft', 'embargoed'] as $which) {
            $this->files[$which] = app(InstantiationService::class)->upload($this->$which, $this->image(), "$which.png", 'en', 'Scan', null, 'online', $this->admin)->files()->first();
        }
        app(Embargoes::class)->impose($this->embargoed, ['type' => 'full', 'reason' => 'privacy'], false, $this->admin);

        $hill = Institution::create(['name' => 'Hillside Archives', 'base_iri' => self::OTHER]);
        app(RoleService::class)->seedDefaults($hill);
        $other = app(AccountService::class)->create($hill, 'Other admin', 'other@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($other, 'administrator');
        app(SearchIndex::class)->flush();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->store)) {
            exec('rm -rf '.escapeshellarg($this->store));
        }
        parent::tearDown();
    }

    private function image(): string
    {
        $image = new Imagick;
        $image->newImage(60, 40, new ImagickPixel('#d8c9a3'));
        $image->setImageFormat('png');
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($path, $image->getImageBlob());

        return $path;
    }

    /** Every address that names one record or its file, on a given site. */
    private function addresses(string $site, RecordResource $record, ?DigitalFile $file): array
    {
        $id = $record->id;

        return [
            "$site/discover/records/$id", "$site/discover/records/$id/tree", "$site/discover/records/$id/iiif", "$site/discover/graph/records/$id",
            "$site/iiif/records/$id/manifest", "$site/iiif/records/$id/collection", "$site/iiif/records/$id/search?q=bridge", "$site/iiif/records/$id/autocomplete?q=br",
            "$site/api/ric/v1/records/$id", "$site/api/ric/v1/records/$id/export", "$site/api/ric/v1/records/$id/files", "$site/cite/$id",
            ...($file ? ["$site/discover/files/{$file->id}"] : []),
        ];
    }

    public function test_a_visitor_reaches_no_unpublished_or_embargoed_record_by_its_address(): void
    {
        foreach (['draft' => $this->draft, 'embargoed' => $this->embargoed] as $which => $record) {
            foreach ($this->addresses(self::SITE, $record, $this->files[$which]) as $address) {
                $status = $this->get($address)->getStatusCode();
                $this->assertContains($status, [401, 403, 404, 410], "$which: $address answered $status");
            }
            $probe = $this->getJson(self::SITE.'/iiif/auth/probe/'.$this->files[$which]->id)->json('status');
            $this->assertNotSame(200, $probe, "$which: the IIIF probe offers the file");
            $this->post(self::SITE.'/cart/add/'.$record->id)->assertSessionHasErrors();
        }
        // The published letter's own file is delivered, so the refusals above are not a broken route.
        $this->get(self::SITE.'/discover/records/'.$this->letter->id)->assertOk();
        $this->get(self::SITE.'/api/ric/v1/records/'.$this->letter->id)->assertOk();
    }

    public function test_no_list_search_or_harvest_names_an_unpublished_or_embargoed_record(): void
    {
        $lists = ['/discover', '/discover?q=minutes', '/discover?q=valuation', '/api/ric/v1/records', '/api/ric/v1/records?q=minutes',
            '/api/ric/v1/autocomplete?q=Unpub', '/api/ric/v1/autocomplete?q=Embarg', '/api/ric/v1/dataset', '/api/ric/v1/changelog',
            '/api/ric/v1/oai?verb=ListRecords&metadataPrefix=oai_dc', '/api/ric/v1/oai?verb=ListIdentifiers&metadataPrefix=oai_dc',
            '/iiif/collection', '/iiif/discovery/changes', '/discover/records/'.$this->letter->id, '/discover/records/'.$this->letter->parent_id.'/tree'];
        // OAI-PMH repeats the identifier it was asked for, and says there is no such record.
        $this->get(self::SITE.'/api/ric/v1/oai?verb=GetRecord&metadataPrefix=oai_dc&identifier='.urlencode($this->draft->iri))->assertSee('idDoesNotExist')->assertDontSee('Unpublished minutes');
        foreach ($lists as $list) {
            $body = $this->get(self::SITE.$list)->getContent();
            foreach ([$this->draft, $this->embargoed] as $record) {
                foreach ([$record->id, $record->iri, 'Unpublished minutes', 'Embargoed valuation'] as $trace) {
                    $this->assertStringNotContainsString($trace, $body, "$list names $trace");
                }
            }
        }
    }

    public function test_another_institutions_catalogue_and_api_answer_nothing_of_this_one(): void
    {
        foreach ($this->addresses(self::OTHER, $this->letter, $this->files['letter']) as $address) {
            $this->assertContains($this->get($address)->getStatusCode(), [401, 403, 404, 410], "$address answered on the other site");
        }
        foreach (['/discover?q=bridge', '/api/ric/v1/records', '/api/ric/v1/oai?verb=ListRecords&metadataPrefix=oai_dc', '/iiif/collection'] as $list) {
            $this->assertStringNotContainsString('Letter about the parish bridge', $this->get(self::OTHER.$list)->getContent(), "$list on the other site");
        }
        // A forwarded host is not trusted; an unknown host has no catalogue when there are two institutions.
        $this->get(self::SITE.'/discover/records/'.$this->letter->id, ['X-Forwarded-Host' => 'hills.example.org'])->assertOk()->assertSee('Letter about the parish bridge');
        $this->get('https://unknown.example.org/discover')->assertNotFound();
        // The application's own file route serves nothing without its signature.
        $this->assertContains($this->get(self::SITE.'/storage/'.$this->files['letter']->storage_path)->getStatusCode(), [403, 404]);
    }

    public function test_an_api_key_reads_only_its_own_institution_and_only_while_it_holds(): void
    {
        $keys = app(ApiKeyService::class);
        [, $good] = $keys->issue($this->admin, 'Harvester', ['read'], null, null, $this->admin);
        [$revoked, $revokedSecret] = $keys->issue($this->admin, 'Old harvester', ['read'], null, null, $this->admin);
        $keys->revoke($revoked, $this->admin);
        [, $expired] = $keys->issue($this->admin, 'Short harvester', ['read'], CarbonImmutable::now()->addDay(), null, $this->admin);
        [, $theirs] = $keys->issue(User::where('email', 'other@example.org')->sole(), 'Their harvester', ['read', 'write'], null, null);

        $record = self::SITE.'/api/ric/v1/records/'.$this->letter->id;
        $this->getJson($record, ['X-API-Key' => $good])->assertOk();
        $this->getJson($record, ['X-API-Key' => $revokedSecret])->assertUnauthorized();
        $this->travel(2)->days();
        $this->getJson($record, ['X-API-Key' => $expired])->assertUnauthorized();
        $this->assertContains($this->getJson($record, ['X-API-Key' => $theirs])->getStatusCode(), [401, 403]);
        // A key reads as the public: a draft stays hidden from it.
        $this->getJson(self::SITE.'/api/ric/v1/records/'.$this->draft->id, ['X-API-Key' => $good])->assertNotFound();
        // Proposals need the write scope of this institution.
        $proposal = self::SITE.'/api/ric/v1/records/'.$this->letter->id.'/proposals';
        $this->assertContains($this->postJson($proposal, ['title' => 'x'], ['X-API-Key' => $good])->getStatusCode(), [401, 403]);
        $this->assertContains($this->postJson($proposal, ['title' => 'x'], ['X-API-Key' => $theirs])->getStatusCode(), [401, 403]);
        $this->assertContains($this->postJson($proposal, ['title' => 'x'])->getStatusCode(), [401, 403]);
    }
}
