<?php

// OpenRiC Workspace - IIIF (roadmap phase 14a, ADR 0014): a published record's manifest paints the reference copies a
// visitor may receive, with the record's titles, summary, metadata, rights, holder, homepage and RiC-O description;
// a record with parts is a collection; curated collections are kept, ordered and published; Change Discovery lists
// each manifest's latest change and withdrawals; a Content State opens the viewer at its canvas; RiC-O names the
// manifest; and nothing a visitor may not see is in any of it.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The IIIF Presentation 3 requirements checked here are the specification's (https://iiif.io/api/presentation/3.0/):
// the presentation-validator's JSON Schema has no licence, so it is not copied in.

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\DateService;
use App\Description\EntityDeletion;
use App\Description\InstantiationService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Iiif\ContentState;
use App\Models\IiifCollection;
use App\Models\Instantiation;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Imagick;
use ImagickPixel;
use Tests\TestCase;

class IiifTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private string $store;

    private User $editor;

    private RecordResource $fonds;

    private RecordResource $letter;

    private RecordResource $closed;

    private Instantiation $scan;

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
        $records = app(RecordService::class);
        $holder = app(RepositoryService::class)->create($institution, 'Riverside Archives', 'en', 'RA');
        $this->fonds = $records->create($institution, self::LEVEL.'fonds', 'Riverside Parish Council records', 'en', null, $holder, 'RPC');
        $this->letter = $records->create($institution, self::LEVEL.'item', 'Letter of 3 May 1902', 'en', $this->fonds, null, '1');
        $records->describe($this->letter, ['scope-and-content' => 'Names the witness at the marriage.', 'extent' => '1 leaf'], 'en');
        $records->describe($this->letter, ['title' => 'Brief van 3 Mei 1902'], 'af');
        app(DateService::class)->add($this->letter, ['property' => 'creation', 'normalized' => '1902-05-03']);
        $this->closed = $records->create($institution, self::LEVEL.'item', 'Closed personnel file', 'en', $this->fonds, null, '2');
        $this->scan = app(InstantiationService::class)->upload($this->letter, $this->image(800, 600), 'letter.png', 'en', 'Scan of the letter');
        app(InstantiationService::class)->upload($this->closed, $this->image(400, 300), 'closed.png', 'en', 'Scan of the file');
        foreach ([$holder, $this->fonds, $this->letter] as $entity) {
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

    private function image(int $width, int $height): string
    {
        $image = new Imagick;
        $image->newImage($width, $height, new ImagickPixel('#d8c9a3'));
        $image->setImageFormat('png');
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($path, $image->getImageBlob());

        return $path;
    }

    /** What IIIF Presentation 3 requires of every resource: an http id, a type, and a label that is a language map. */
    private function resource(array $resource, string $type): void
    {
        $this->assertMatchesRegularExpression('~^https?://~', $resource['id'] ?? '', "$type id");
        $this->assertSame($type, $resource['type'] ?? null);
        if (isset($resource['label'])) {
            foreach ($resource['label'] as $language => $strings) {
                $this->assertIsString($language);
                $this->assertIsList($strings, "$type label is a language map");
            }
        }
    }

    public function test_a_published_record_has_a_manifest_of_the_files_a_visitor_may_receive(): void
    {
        $response = $this->get(route('iiif.manifest', $this->letter->id))->assertOk()
            ->assertHeader('Content-Type', 'application/ld+json;profile="http://iiif.io/api/presentation/3/context.json"')->assertHeader('Access-Control-Allow-Origin', '*');
        $manifest = $response->json();
        $this->assertSame('http://iiif.io/api/presentation/3/context.json', $manifest['@context']);
        $this->resource($manifest, 'Manifest');
        $this->assertSame(['en' => ['Letter of 3 May 1902'], 'af' => ['Brief van 3 Mei 1902']], $manifest['label']);
        $this->assertSame(['en' => ['Names the witness at the marriage.']], $manifest['summary']);
        $this->assertContains(['label' => ['en' => ['Reference code']], 'value' => ['none' => ['RA RPC/1']]], $manifest['metadata']);
        $this->assertSame(['none' => ['Riverside Archives']], $manifest['requiredStatement']['value']);
        $this->assertSame('1902-05-03T00:00:00Z', $manifest['navDate']);
        $this->assertSame(route('discover.record', $this->letter->id), $manifest['homepage'][0]['id']);
        $this->assertSame(route('api.record', $this->letter->id), $manifest['seeAlso'][0]['id'], 'the RiC-O description it presents');
        $this->assertSame(route('iiif.collection', $this->fonds->id), $manifest['partOf'][0]['id']);

        $this->assertCount(1, $manifest['items']);
        $canvas = $manifest['items'][0];
        $this->resource($canvas, 'Canvas');
        $painting = $canvas['items'][0]['items'][0];
        $this->assertSame(['AnnotationPage', 'Annotation', 'painting', $canvas['id']], [$canvas['items'][0]['type'], $painting['type'], $painting['motivation'], $painting['target']]);
        $reference = $this->scan->file('reference');
        $this->assertSame([route('discover.file', $reference), 'Image', 'image/jpeg'], [$painting['body']['id'], $painting['body']['type'], $painting['body']['format']]);
        $this->assertSame([$canvas['width'], $canvas['height']], [$painting['body']['width'], $painting['body']['height']]);
        $this->assertGreaterThan(0, $canvas['width']);
        $this->assertArrayNotHasKey('service', $painting['body'], 'no IIIF Image API server, so no image service');
        $this->get($painting['body']['id'])->assertOk();
        $this->assertStringNotContainsString(route('discover.file', $this->scan->file('master')), $response->getContent(), 'the master is not the public\'s');

        // A record with nothing a visitor may see has no manifest; an unpublished one is not there at all.
        $this->get(route('iiif.manifest', $this->fonds->id))->assertNotFound();
        $this->get(route('iiif.manifest', $this->closed->id))->assertNotFound();
        app(InstantiationService::class)->updateFacts($this->scan, 'digital-file', 'withdrawn');
        $this->get(route('iiif.manifest', $this->letter->id))->assertNotFound();
    }

    public function test_a_record_with_parts_is_a_collection_and_the_institution_has_one_of_everything(): void
    {
        $collection = $this->get(route('iiif.collection', $this->fonds->id))->assertOk()->json();
        $this->resource($collection, 'Collection');
        $this->assertSame([[route('iiif.manifest', $this->letter->id), 'Manifest']], array_map(fn ($i) => [$i['id'], $i['type']], $collection['items']),
            'the letter, not the unpublished file');
        $top = $this->get(route('iiif.top'))->assertOk()->json();
        $this->assertSame([route('iiif.collection', $this->fonds->id)], array_column($top['items'], 'id'));
    }

    public function test_curated_collections_are_kept_ordered_and_published_with_what_the_public_may_see(): void
    {
        $this->actingAs($this->editor)->post(route('iiif-collections.store'), ['label' => 'Marriages', 'summary' => 'Letters about marriages.', 'behavior' => 'individuals', 'is_public' => '1'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $curated = IiifCollection::sole();
        $add = fn (array $data) => $this->actingAs($this->editor)->post(route('iiif-collections.items.add', $curated), $data);
        $add(['target' => $this->letter->iri])->assertSessionHasNoErrors();
        $add(['target' => $this->closed->iri])->assertSessionHasNoErrors();
        $add(['target' => 'https://iiif.example.org/manifest/7', 'type' => 'manifest', 'label' => 'A letter elsewhere'])->assertSessionHasNoErrors();
        $add(['target' => 'not a uri'])->assertSessionHasErrors('item');
        $outside = $curated->items()->get()->last();
        $this->actingAs($this->editor)->post(route('iiif-collections.items.move', [$curated, $outside, 'up']))->assertRedirect();
        $this->actingAs($this->editor)->get(route('iiif-collections.show', $curated))->assertOk()->assertSeeInOrder(['Letter of 3 May 1902', 'A letter elsewhere', 'Closed personnel file']);

        $json = $this->get(route('iiif.curated', $curated))->assertOk()->json();
        $this->resource($json, 'Collection');
        $this->assertSame(['individuals'], $json['behavior']);
        $this->assertSame([[route('iiif.manifest', $this->letter->id), 'Manifest'], ['https://iiif.example.org/manifest/7', 'Manifest']],
            array_map(fn ($i) => [$i['id'], $i['type']], $json['items']), 'in order, without what the public may not see');
        $this->assertContains(route('iiif.curated', $curated), array_column($this->get(route('iiif.top'))->json('items'), 'id'));

        $this->actingAs($this->editor)->post(route('iiif-collections.update', $curated), ['label' => 'Marriages', 'is_public' => '0'])->assertRedirect();
        auth()->logout();
        $this->get(route('iiif.curated', $curated))->assertNotFound();
        $translator = app(AccountService::class)->create(Institution::sole(), 'Translator', 'translator@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($translator, 'translator');
        $this->actingAs($translator)->get(route('iiif-collections.index'))->assertForbidden();
    }

    public function test_change_discovery_lists_each_manifest_and_each_withdrawal(): void
    {
        $root = $this->get(route('iiif.changes'))->assertOk()->json();
        $this->assertSame(['OrderedCollection', 1], [$root['type'], $root['totalItems']]);
        $page = $this->get($root['last']['id'])->assertOk()->json();
        $this->assertSame([['Update', route('iiif.manifest', $this->letter->id)]], array_map(fn ($a) => [$a['type'], $a['object']['id']], $page['orderedItems']));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $page['orderedItems'][0]['endTime']);

        // The scan goes first, as a record with instantiations is not deleted; the record had a manifest when the public last saw it.
        app(EntityDeletion::class)->delete($this->scan, $this->editor);
        $this->actingAs($this->editor)->post("/records/{$this->letter->id}/delete", ['reason' => 'Catalogued twice.'])->assertRedirect()->assertSessionHasNoErrors();
        auth()->logout();
        $page = $this->get(route('iiif.changes', ['page' => 1]))->json();
        $this->assertSame([['Delete', route('iiif.manifest', $this->letter->id)]], array_map(fn ($a) => [$a['type'], $a['object']['id']], $page['orderedItems']));
        $this->get(route('iiif.manifest', $this->letter->id))->assertStatus(410);
        $this->get(route('iiif.changes', ['page' => 9]))->assertNotFound();
    }

    public function test_a_content_state_opens_the_viewer_at_its_canvas_and_ric_o_names_the_manifest(): void
    {
        $manifest = $this->get(route('iiif.manifest', $this->letter->id))->json();
        $canvas = $manifest['items'][0]['id'];
        $token = $this->get(route('iiif.content-state', ['manifest' => $manifest['id'], 'canvas' => $canvas, 'region' => 'xywh=0,0,100,100']))->assertOk()->json('iiif-content');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token, 'URL-safe base64 without padding');
        $this->assertSame(['manifest' => $manifest['id'], 'canvas' => $canvas, 'region' => 'xywh=0,0,100,100'], app(ContentState::class)->decode($token));
        $this->assertNull(app(ContentState::class)->decode(app(ContentState::class)->encode('https://elsewhere.example.org/manifest')), 'only this Workspace\'s manifests');
        $this->get(route('discover.iiif', [$this->letter->id, 'iiif-content' => $token]))->assertOk()->assertSee($manifest['id'], false)->assertSee($canvas, false);
        $this->get(route('discover.record', $this->letter->id))->assertOk()->assertSee(route('iiif.manifest', $this->letter->id), false);

        $this->assertSame(['@id' => $manifest['id']], $this->get('/api/ric/v1/records/'.$this->letter->id)->json('rdfs:seeAlso'), 'RiC describes, IIIF presents');
        $this->assertArrayNotHasKey('rdfs:seeAlso', $this->get('/api/ric/v1/records/'.$this->fonds->id)->json());
        $this->assertSame($manifest['id'], $this->get('/api/ric/v1/records/'.$this->letter->id.'/files')->json('iiif_manifest'));
    }
}
