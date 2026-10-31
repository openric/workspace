<?php

// OpenRiC Workspace - IIIF services (roadmap phase 14b, ADR 0014): Content Search 2.0 finds words in a manifest's
// published transcripts and completes them; Authorization Flow 2.0 shows a file on request to who may have it once
// signed in, by probe, access and token services; an account saves and reopens its viewer layouts; and validation
// checks each record's manifest and keeps the results for the dashboard.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\InstantiationService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Iiif\Validation;
use App\Models\IiifValidationResult;
use App\Models\IiifViewerWorkspace;
use App\Models\Instantiation;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Imagick;
use ImagickPixel;
use Tests\TestCase;

class IiifServicesTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private string $store;

    private User $editor;

    private RecordResource $letter;

    private RecordResource $draft;

    private Instantiation $scan;

    private Instantiation $kept;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($institution);
        $this->editor = app(AccountService::class)->create($institution, 'Editor', 'editor@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->editor, 'editor');
        $holder = app(RepositoryService::class)->create($institution, 'Riverside Archives', 'en', 'RA');
        $this->letter = app(RecordService::class)->create($institution, self::LEVEL.'item', 'Letter of 3 May 1902', 'en', null, $holder, 'L1');
        $this->draft = app(RecordService::class)->create($institution, self::LEVEL.'item', 'Unpublished letter', 'en', null, $holder, 'L2');
        $service = app(InstantiationService::class);
        $this->scan = $service->upload($this->letter, $this->image(), 'page-1.png', 'en', 'Page 1', null, 'online', $this->editor);
        $service->describe($this->scan, ['instantiation-transcript' => 'The witness signed. The witness was the parish clerk, a Witness of good standing.'], 'en', $this->editor);
        $this->kept = $service->upload($this->letter, $this->image(), 'page-2.png', 'en', 'Page 2', null, 'on-request', $this->editor);
        $service->describe($this->kept, ['instantiation-transcript' => 'Kept back: the witness lived at the mill.'], 'en', $this->editor);
        $service->upload($this->draft, $this->image(), 'draft.png', 'en', 'Draft page', null, 'online', $this->editor);
        foreach ([$holder, $this->letter] as $entity) {
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

    private function image(): string
    {
        $image = new Imagick;
        $image->newImage(600, 400, new ImagickPixel('#d8c9a3'));
        $image->setImageFormat('png');
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($path, $image->getImageBlob());

        return $path;
    }

    public function test_content_search_finds_words_in_the_published_transcripts_a_visitor_may_read(): void
    {
        $manifest = $this->get(route('iiif.manifest', $this->letter->id))->assertOk()->json();
        $this->assertSame(['http://iiif.io/api/auth/2/context.json', 'http://iiif.io/api/search/2/context.json', 'http://iiif.io/api/presentation/3/context.json'], $manifest['@context'],
            'the services\' contexts first, Presentation last');
        $this->assertSame(['SearchService2', route('iiif.search', $this->letter->id), 'AutoCompleteService2'],
            [$manifest['service'][0]['type'], $manifest['service'][0]['id'], $manifest['service'][0]['service'][0]['type']]);

        $page = $this->get(route('iiif.search', [$this->letter->id, 'q' => 'witness']))->assertOk()->json();
        $this->assertSame('AnnotationPage', $page['type']);
        $this->assertSame(['witness', 'witness', 'Witness'], array_map(fn ($a) => $a['body']['value'], $page['items']), 'every occurrence, as written; not the text kept back');
        $canvas = $manifest['items'][0]['id'];
        $this->assertSame([$canvas, 'highlighting'], [$page['items'][0]['target']['source']['id'], $page['items'][0]['motivation']]);
        $context = $page['annotations'][0]['items'][0];
        $this->assertSame(['contextualizing', $page['items'][0]['id'], 'The ', 'witness', ' signed. The witness was the parish clerk, a Witness of good'],
            [$context['motivation'], $context['target']['source']['id'], $context['target']['selector'][0]['prefix'], $context['target']['selector'][0]['exact'], $context['target']['selector'][0]['suffix']],
            'the words around it: sixty characters either side');
        $this->assertSame([], $this->get(route('iiif.search', [$this->letter->id, 'q' => 'mill']))->json('items'), 'the on-request page\'s text is not searched');

        $terms = $this->get(route('iiif.autocomplete', [$this->letter->id, 'q' => 'wi']))->assertOk()->json();
        $this->assertSame(['TermPage', [['witness', 3]]], [$terms['type'], array_map(fn ($t) => [$t['value'], $t['total']], $terms['items'])]);
        $this->get(route('iiif.search', [$this->draft->id, 'q' => 'x']))->assertNotFound();
    }

    public function test_a_file_on_request_is_shown_to_who_may_have_it_once_signed_in(): void
    {
        $manifest = $this->get(route('iiif.manifest', $this->letter->id))->json();
        $this->assertCount(2, $manifest['items']);
        $body = $manifest['items'][1]['items'][0]['items'][0]['body'];
        $probe = $body['service'][0];
        $reference = $this->kept->file('reference');
        $this->assertSame([route('iiif.auth.probe', $reference), 'AuthProbeService2', 'AuthAccessService2', 'active', 'AuthAccessTokenService2'],
            [$probe['id'], $probe['type'], $probe['service'][0]['type'], $probe['service'][0]['profile'], $probe['service'][0]['service'][0]['type']]);
        $this->assertArrayNotHasKey('service', $manifest['items'][0]['items'][0]['items'][0]['body'], 'an open file needs no probe');
        $this->assertArrayNotHasKey('thumbnail', $manifest['items'][1], 'nor is its thumbnail shown');

        // Not signed in: the probe says 401, the file is not sent, the access service asks for the sign-in.
        $this->get($probe['id'])->assertOk()->assertJson(['type' => 'AuthProbeResult2', 'status' => 401]);
        $this->call('OPTIONS', $probe['id'])->assertNoContent()->assertHeader('Access-Control-Allow-Headers', 'Authorization');
        $this->get($body['id'])->assertNotFound();
        $this->get(route('iiif.auth.access'))->assertRedirect(route('sign-in'));
        $frame = $this->get(route('iiif.auth.token', ['origin' => 'https://viewer.example.org', 'messageId' => 'm1']))->assertOk()->getContent();
        $this->assertStringContainsString('"type":"AuthAccessTokenError2"', $frame);

        // Signed in with the right to it: the token service posts a token to the viewer's window, and the probe says 200.
        $this->actingAs($this->editor);
        $this->get(route('iiif.auth.access'))->assertOk()->assertSee('window.close()', false);
        $frame = $this->get(route('iiif.auth.token', ['origin' => 'https://viewer.example.org', 'messageId' => 'm2']))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->getContent();
        $this->assertMatchesRegularExpression('/window\.parent\.postMessage\(\{.*"type":"AuthAccessToken2".*"messageId":"m2"\}, "https:\/\/viewer\.example\.org"\)/', $frame);
        preg_match('/"accessToken":"([^"]+)"/', $frame, $m);
        $this->get($body['id'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        auth()->logout();
        $this->get($probe['id'], ['Authorization' => 'Bearer '.$m[1]])->assertJson(['status' => 200]);
        $this->get($probe['id'], ['Authorization' => 'Bearer not-a-token'])->assertJson(['status' => 401]);
        $this->assertStringNotContainsString('postMessage', $this->actingAs($this->editor)->get(route('iiif.auth.token', ['origin' => 'javascript:alert(1)']))->getContent(),
            'only to a web origin');
    }

    public function test_an_account_saves_its_viewer_layout_and_opens_it_again(): void
    {
        $manifest = route('iiif.manifest', $this->letter->id);
        $canvas = $manifest.'/canvas/'.$this->scan->id;
        $this->actingAs($this->editor)->get(route('discover.iiif', $this->letter->id))->assertOk()->assertSee(__('Save layout'));
        $this->actingAs($this->editor)->post(route('iiif-workspaces.store'), ['name' => 'Witnesses', 'windows' => json_encode([
            ['manifestId' => $manifest, 'canvasId' => $canvas], ['manifestId' => 'https://elsewhere.example.org/manifest'], ['manifestId' => $manifest, 'canvasId' => 'https://evil.example.org/c'],
        ])])->assertRedirect(route('iiif-workspaces.index'));
        $saved = IiifViewerWorkspace::sole();
        $this->assertEquals([['manifestId' => $manifest, 'canvasId' => $canvas], ['manifestId' => $manifest]], $saved->windows, 'only this Workspace\'s manifests and their canvases');
        $this->actingAs($this->editor)->post(route('iiif-workspaces.update', $saved), ['default' => '1'])->assertRedirect();
        $this->assertTrue($saved->fresh()->is_default);
        $this->actingAs($this->editor)->get(route('iiif-workspaces.open', $saved))->assertRedirect(route('discover.iiif', [$this->letter->id, 'workspace' => $saved->id]));
        $this->actingAs($this->editor)->get(route('discover.iiif', [$this->letter->id, 'workspace' => $saved->id]))->assertOk()->assertSee($canvas, false);

        $other = app(AccountService::class)->create(Institution::sole(), 'Other', 'other@example.org', 'correct horse battery staple');
        $this->actingAs($other)->get(route('iiif-workspaces.open', $saved))->assertNotFound();
        $this->actingAs($other)->get(route('discover.iiif', [$this->letter->id, 'workspace' => $saved->id]))->assertNotFound();
        auth()->logout();
        $this->get(route('discover.iiif', $this->letter->id))->assertOk()->assertDontSee(__('Save layout'));
    }

    public function test_validation_checks_each_record_and_keeps_the_results(): void
    {
        $results = collect(app(Validation::class)->validate($this->letter, $this->editor))->pluck('status', 'check')->all();
        $this->assertSame(['label-present' => 'passed', 'has-canvases' => 'passed', 'files-present' => 'passed', 'dimensions' => 'passed', 'durations' => 'passed',
            'thumbnail-present' => 'passed', 'rights-present' => 'warning', 'required-statement' => 'passed', 'published' => 'passed'], $results);

        $this->actingAs($this->editor)->post(route('iiif-validation.all'))->assertRedirect()->assertSessionHas('status', '2 checked: 0 passed, 2 with warnings, 0 failed.');
        $this->assertSame('warning', IiifValidationResult::where('record_resource_id', $this->draft->id)->whereHas('check', fn ($q) => $q->where('iri', Validation::CHECKS.'published'))
            ->latest('validated_at')->first()->status->notation, 'a draft is checked, and not yet published');
        $this->actingAs($this->editor)->get(route('iiif-validation.index'))->assertOk()->assertSee('Records checked');
        $this->actingAs($this->editor)->get(route('iiif-validation.record', $this->letter))->assertOk()->assertSee('No Creative Commons licence');
        $this->assertSame(['records' => 2, 'passed' => 0, 'warning' => 2, 'failed' => 0],
            array_intersect_key(app(Validation::class)->dashboard(Institution::sole()), array_flip(['records', 'passed', 'warning', 'failed'])));

        $translator = app(AccountService::class)->create(Institution::sole(), 'Translator', 'translator@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($translator, 'translator');
        $this->actingAs($translator)->get(route('iiif-validation.index'))->assertForbidden();
    }
}
