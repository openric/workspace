<?php

// OpenRiC Workspace - the public catalogue and its graph (WS-009; the V0.1 demonstration steps 9 and 10).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\ActivityService;
use App\Description\AgentService;
use App\Description\InstantiationService;
use App\Description\PlaceService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Activity;
use App\Models\Agent;
use App\Models\Concept;
use App\Models\Instantiation;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use App\Relations\RelationService;
use App\Vocabulary\ConceptService;
use App\Vocabulary\SkosImporter;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Imagick;
use ImagickPixel;
use Tests\TestCase;

class DiscoverTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private string $store;

    private RecordResource $fonds;

    private RecordResource $item;

    private RecordResource $closed;

    private Agent $clerk;

    private Activity $wedding;

    private Instantiation $scan;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        $this->loadBundledVocabularies();
        foreach (['role-type', 'activity-type'] as $fixture) {
            app(SkosImporter::class)->import(file_get_contents(base_path("tests/Fixtures/$fixture.ttl")));
        }
        $institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($institution);
        $this->editor = app(AccountService::class)->create($institution, 'Editor', 'editor@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->editor, 'editor');
        $records = app(RecordService::class);
        $relations = app(RelationService::class);
        $holder = app(RepositoryService::class)->create($institution, 'Riverside Archives', 'en', 'RA');
        $this->fonds = $records->create($institution, self::LEVEL.'fonds', 'Riverside Parish Council records', 'en', null, $holder, 'RPC');
        $this->item = $records->create($institution, self::LEVEL.'item', 'Letter of 3 May 1902', 'en', $this->fonds, null, '1');
        $records->describe($this->item, ['scope-and-content' => 'Names the witness at the marriage.'], 'en');
        $this->closed = $records->create($institution, self::LEVEL.'item', 'Closed personnel file', 'en', $this->fonds, null, '2');
        $this->clerk = app(AgentService::class)->create($institution, 'person', 'Botha, Jan (parish clerk)', 'en');
        $this->wedding = app(ActivityService::class)->create($institution, 'Marriage of Johanna Engelbrecht and Pieter de Wet', 'en',
            Concept::where('iri', Activity::TYPES.'production')->value('id'));
        $relations->create($this->wedding, 'participation', $this->clerk, ['role' => 'witness'], $this->editor);
        $relations->create($this->item, 'documentation', $this->wedding, [], $this->editor);
        $relations->create($this->item, 'creation', $this->clerk, [], $this->editor);
        $place = app(PlaceService::class)->create($institution, 'Riverside', 'en');
        $relations->create($place, 'place', $this->wedding, [], $this->editor);
        $concepts = app(ConceptService::class);
        $relations->create($this->item, 'subject', $concepts->create($concepts->schemeOfKind($institution, 'subject'), ['label' => 'Marriages', 'language' => 'en']), [], $this->editor);
        $image = new Imagick;
        $image->newImage(800, 600, new ImagickPixel('#d8c9a3'));
        $image->setImageFormat('png');
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($path, $image->getImageBlob());
        $this->scan = app(InstantiationService::class)->upload($this->item, $path, 'letter.png', 'en', 'Scan of the letter');

        $publication = app(PublicationService::class);
        foreach ([$holder, $this->fonds, $this->item, $this->clerk, $this->wedding, $place] as $entity) {
            $publication->apply($entity, 'publish', null, false, $this->editor);
        }
        // Published, then changed: the change is a draft.
        $records->describe($this->item, ['archivists-note' => 'Draft note, not yet reviewed.'], 'en');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->store)) {
            exec('rm -rf '.escapeshellarg($this->store));
        }
        parent::tearDown();
    }

    public function test_step_9_only_what_is_published_appears_and_drafts_never(): void
    {
        $this->get('/')->assertRedirect(route('discover.home'));
        $this->get('/discover')->assertOk()->assertSee('Riverside Parish Council records')->assertSee('2 archival descriptions')->assertDontSee('Closed personnel file');
        $this->get('/discover?q=letter')->assertOk()->assertSee('Letter of 3 May 1902')->assertDontSee('Closed personnel');
        $this->get('/discover?q=closed')->assertSee('Nothing found.');
        $this->get('/discover?q=botha&kind=agents')->assertSee('Botha, Jan (parish clerk)');
        $this->get("/discover/records/{$this->closed->id}")->assertNotFound();
        $this->get("/discover/graph/records/{$this->closed->id}")->assertNotFound();

        $page = $this->get("/discover/records/{$this->item->id}")->assertOk();
        $page->assertSee('Names the witness at the marriage.')->assertDontSee('Draft note, not yet reviewed.')->assertSee('Botha, Jan (parish clerk)')
            ->assertSee('Marriages')->assertSee('Documents')->assertSee('rico:scopeAndContent')->assertSee('Scan of the letter');
        $this->assertStringNotContainsString('Closed personnel file', $this->get("/discover/records/{$this->fonds->id}")->getContent());
        // The image comes first, above the title, on the public page and on the staff page.
        $page->assertSeeInOrder([route('discover.file', $this->scan->file('reference')), '<h1'], false);
        $this->actingAs($this->editor)->get(route('records.show', $this->item))->assertOk()
            ->assertSeeInOrder(['aria-label="Digital copies"', route('files.show', $this->scan->file('reference')), '<h1>'], false);
        auth()->logout();

        // The reference copy reaches the public; the master does not (the Public role has no read-master).
        $this->get(route('discover.file', $this->scan->file('reference')))->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get(route('discover.file', $this->scan->file('master')))->assertNotFound();
        app(InstantiationService::class)->updateFacts($this->scan, 'digital-file', 'withdrawn');
        $this->get(route('discover.file', $this->scan->file('reference')))->assertNotFound();
    }

    public function test_the_home_page_shows_the_introduction_the_collections_carousel_and_the_credits(): void
    {
        $institution = Institution::find($this->fonds->institution_id);
        $institution->update(['catalogue_introduction' => "Records of the parish council.\n\nSee https://example.org/about for more. <script>alert(1)</script>",
            'catalogue_credits' => 'Place names: Example Gazetteer, version 2 (2026), https://example.org/gazetteer']);
        app(RecordService::class)->create($institution, self::LEVEL.'fonds', 'Unpublished vestry minutes', 'en', null, $this->fonds->repository, 'VM');

        $page = $this->get('/discover')->assertOk()
            ->assertSee('Records of the parish council.')->assertSee('<a href="https://example.org/about">https://example.org/about</a>', false)
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false)
            ->assertSee('<h2 id="credits">Credits</h2>', false)->assertSee('<a href="https://example.org/gazetteer">', false)
            ->assertSee('aria-roledescription="carousel"', false)->assertSee('class="carousel-pause" aria-pressed="false"', false);
        // The fonds is shown with the picture of the letter inside it, and how much it holds; a draft never is.
        preg_match('~<section class="collections-carousel".*?</section>~s', $page->getContent(), $carousel);
        $this->assertStringContainsString('Riverside Parish Council records', $carousel[0]);
        $this->assertStringContainsString(route('discover.file', $this->scan->file('thumbnail')), $carousel[0]);
        $this->assertStringContainsString('1 unit inside', $carousel[0]);
        $this->assertStringNotContainsString('Unpublished vestry minutes', $page->getContent());

        $institution->update(['catalogue_introduction' => null, 'catalogue_credits' => null]);
        $this->get('/discover')->assertDontSee('id="credits"', false)->assertDontSee('class="catalogue-lede"', false);
    }

    public function test_the_hierarchy_tree_stands_beside_a_record_with_only_what_the_reader_may_see(): void
    {
        // Heratio's sidebar treeview: the units above, the current one marked, its children or its neighbours.
        $page = $this->get("/discover/records/{$this->item->id}")->assertOk()->getContent();
        preg_match('~<nav class="tree" aria-label="Hierarchy">.*?</nav>~s', $page, $tree);
        $this->assertStringContainsString('href="'.route('discover.record', $this->fonds->id).'"', $tree[0]);
        $this->assertStringContainsString('<strong aria-current="page">Letter of 3 May 1902</strong>', $tree[0]);
        $this->assertStringNotContainsString('Closed personnel file', $tree[0], 'a draft is not in the public tree');

        $fonds = $this->get("/discover/records/{$this->fonds->id}")->getContent();
        preg_match('~<nav class="tree" aria-label="Hierarchy">.*?</nav>~s', $fonds, $tree);
        $this->assertStringContainsString('Letter of 3 May 1902', $tree[0]);
        $this->assertStringNotContainsString('Closed personnel file', $tree[0]);
        $this->get("/discover/records/{$this->fonds->id}/tree")->assertOk()->assertJsonPath('items.0.title', 'Letter of 3 May 1902')->assertJsonCount(1, 'items')->assertJsonPath('more', false);
        $this->get("/discover/records/{$this->closed->id}/tree")->assertNotFound();

        // Signed in, the closed file is there.
        $this->actingAs($this->editor)->get("/records/{$this->fonds->id}")->assertSee('<nav class="tree" aria-label="Hierarchy">', false)->assertSee('Closed personnel file');
        $this->actingAs($this->editor)->get("/records/{$this->fonds->id}/tree-branch")->assertOk()->assertJsonCount(2, 'items');
    }

    public function test_a_unit_with_no_picture_of_its_own_shows_the_pictures_of_the_units_inside_it(): void
    {
        // Heratio's imageflow: the fonds has no file, the letter inside it has the scan.
        $page = $this->get("/discover/records/{$this->fonds->id}")->assertOk()->assertSee('aria-label="Pictures of the units inside"', false)->getContent();
        preg_match('~<section aria-label="Pictures of the units inside".*?</section>~s', $page, $strip);
        $this->assertStringContainsString(route('discover.record', $this->item->id), $strip[0]);
        $this->assertStringContainsString(route('discover.file', $this->scan->file('thumbnail')), $strip[0]);
        $this->assertStringNotContainsString('Closed personnel file', $strip[0]);
        // The letter shows its own scan, and no strip.
        $this->get("/discover/records/{$this->item->id}")->assertDontSee('aria-label="Pictures of the units inside"', false);
        // The same strip on the staff page.
        $this->actingAs($this->editor)->get("/records/{$this->fonds->id}")->assertSee('aria-label="Pictures of the units inside"', false)->assertSee(route('records.show', $this->item), false);
    }

    public function test_a_repository_lists_the_published_units_it_holds_at_the_top_and_no_draft(): void
    {
        $repository = $this->fonds->repository;
        app(RecordService::class)->create($repository->institution, self::LEVEL.'fonds', 'Unpublished vestry minutes', 'en', null, $repository, 'VM');

        $this->get("/discover/repositories/{$repository->id}")->assertOk()
            ->assertSee('Holdings (1)')->assertSee('rico:isOrWasHolderOf')
            ->assertSee('href="'.route('discover.record', $this->fonds->id).'"', false)->assertSee('Riverside Parish Council records')
            ->assertDontSee('Unpublished vestry minutes')->assertDontSee('Closed personnel file')->assertDontSee('Letter of 3 May 1902');
    }

    public function test_step_10_the_graph_reaches_the_clerk_and_the_marriage_and_every_node_opens_its_page(): void
    {
        $response = $this->get("/discover/graph/records/{$this->item->id}?depth=2")->assertOk();
        $html = $response->getContent();
        foreach (['Botha, Jan (parish clerk)', 'Marriage of Johanna Engelbrecht and Pieter de Wet', 'Riverside Parish Council records', 'Riverside'] as $name) {
            $this->assertTrue(str_contains($html, $name), "$name is in the graph");
        }
        $this->assertStringNotContainsString('Closed personnel file', $html);
        $response->assertSee('documents')->assertSee('has or had as a participant');

        $document = new DOMDocument;
        @$document->loadHTML($html);
        $xpath = new DOMXPath($document);
        $this->assertSame(1, $xpath->query('//svg[@role="group"][@aria-labelledby="graph-title graph-desc"]')->length);
        // Invariant 5: every node is a RiC-O 1.1 or OpenRiC extension class.
        $classes = ['rico' => array_flip(file(resource_path('ontologies/rico-1.1.terms'), FILE_IGNORE_NEW_LINES)), 'openricx' => array_flip(file(resource_path('ontologies/openricx-ext.terms'), FILE_IGNORE_NEW_LINES))];
        $nodes = $xpath->query('//h3[.="Entities"]/following-sibling::ul[1]/li');
        $this->assertGreaterThanOrEqual(6, $nodes->length);
        foreach ($nodes as $li) {
            preg_match('/\b(rico|openricx):(\w+)/', $li->textContent, $m);
            $this->assertArrayHasKey($m[2] ?? '', $classes[$m[1] ?? 'rico'] ?? [], 'a node class from RiC-O or the extension: '.$li->textContent);
        }
        // Invariant 7, for people: every node with a page opens its own description.
        $links = collect(iterator_to_array($xpath->query('//svg//a/@href')))->map->value->unique();
        $this->assertGreaterThanOrEqual(5, $links->count());
        foreach ($links as $url) {
            $this->get($url)->assertOk();
        }
        $this->get("/discover/activities/{$this->wedding->id}")->assertOk()->assertSee('Botha, Jan (parish clerk)')->assertSee('as Witness');
        $this->get("/discover/agents/{$this->clerk->id}")->assertOk()->assertSee('Takes or took part in');

        // The same graph as an openric:Subgraph for the 2D and 3D viewer, served from the Workspace itself.
        $data = json_decode($xpath->query('//script[@id="graph-data"]')->item(0)->textContent, true);
        $this->assertSame('openric:Subgraph', $data['@type']);
        $this->assertCount(count($data['openric:nodes']), collect($data['openric:nodes'])->pluck('id')->unique());
        $this->assertContains($this->item->iri, collect($data['openric:nodes'])->pluck('id'));
        $this->assertSame([], collect($data['openric:nodes'])->filter(fn ($n) => $n['type'] === '')->pluck('label')->all());
        $this->assertTrue(collect($data['openric:edges'])->every(fn ($e) => in_array($e['source'], collect($data['openric:nodes'])->pluck('id')->all(), true)));
        $this->assertStringContainsString(asset('vendor/graph/openric-viewer.umd.js'), $html);
        foreach (['cytoscape.min.js', 'openric-viewer.umd.js', 'three.min.js', 'three-spritetext.min.js', '3d-force-graph.min.js'] as $file) {
            $this->assertFileExists(public_path('vendor/graph/'.$file));
        }
    }

    public function test_persistent_iris_open_the_public_page_in_a_browser_and_the_json_ld_for_data(): void
    {
        $this->get($this->item->iri, ['Accept' => 'text/html'])->assertStatus(303)->assertRedirect(route('discover.record', $this->item->id));
        $this->get($this->wedding->iri, ['Accept' => 'text/html'])->assertRedirect(route('discover.activity', $this->wedding->id));
        $this->get($this->item->iri, ['Accept' => 'application/ld+json'])->assertRedirect(route('api.record', $this->item->id));
        $this->actingAs($this->editor)->get($this->item->iri, ['Accept' => 'text/html'])->assertRedirect(route('records.show', $this->item));
        $this->actingAs($this->editor)->get('/')->assertOk();
    }
}
