<?php

// OpenRiC Workspace - the researcher's own workspace: collections, annotations, notebooks, the research diary and
// research workspaces (roadmap phase 19d, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\InstantiationService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Mail\ResearchMail;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\ResearchAnnotation;
use App\Models\ResearchCollection;
use App\Models\ResearchDiaryEntry;
use App\Models\ResearchNotebook;
use App\Models\ResearchWorkspace;
use App\Models\ResearchWorkspaceMember;
use App\Models\SavedSearch;
use App\Models\User;
use App\Research\Collections;
use App\Research\ResearchWorkspaces;
use App\Search\SearchIndex;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ResearcherWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private string $store;

    private Institution $institution;

    private User $admin;

    private User $reader;

    private User $other;

    private RecordResource $letter;

    private RecordResource $draft;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        Mail::fake();
        $this->travelTo('2026-11-03 09:00');
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->admin = app(AccountService::class)->create($this->institution, 'Admin', 'admin@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->admin, 'administrator');
        $this->reader = app(AccountService::class)->create($this->institution, 'Rita', 'rita@example.org', 'correct horse battery staple');
        $this->other = app(AccountService::class)->create($this->institution, 'Olu', 'olu@example.org', 'correct horse battery staple');
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $fonds = app(RecordService::class)->create($this->institution, self::LEVEL.'fonds', 'Parish council records', 'en', null, $holder, 'PC');
        $this->letter = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Letter about the parish bridge', 'en', $fonds, null, '1');
        $this->draft = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Minutes not yet published', 'en', $fonds, null, '2');
        foreach ([$holder, $fonds, $this->letter] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->admin);
        }
        app(SearchIndex::class)->flush();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->store)) {
            exec('rm -rf '.escapeshellarg($this->store));
        }
        parent::tearDown();
    }

    public function test_a_collection_is_made_from_a_record_page_shared_and_printed_as_a_finding_aid(): void
    {
        $this->actingAs($this->reader)->get(route('records.show', $this->letter))->assertOk()->assertSee('Add to a collection')->assertSee(route('research.studio', ['records', $this->letter->id]));
        $this->actingAs($this->reader)->post(route('research.collections.add', ['records', $this->letter->id]), ['new_name' => 'Bridge sources', 'notes' => 'The first mention'])
            ->assertSessionHasNoErrors();
        $collection = ResearchCollection::where('user_id', $this->reader->id)->sole();
        $this->assertSame('Bridge sources', $collection->name);
        $this->assertSame('RA PC/1', $collection->items()->sole()->reference_code);
        // The record cannot be added twice; a draft the reader may not read is not found.
        $this->actingAs($this->reader)->post(route('research.collections.add', ['records', $this->letter->id]), ['collection_id' => $collection->id])->assertSessionHasNoErrors();
        $this->assertSame(1, $collection->items()->count());
        $this->actingAs($this->reader)->post(route('research.collections.add', ['records', $this->draft->id]), ['collection_id' => $collection->id])->assertSessionHasNoErrors();
        // The Workspace writes the diary: a collection made, two records added.
        $this->assertSame(3, ResearchDiaryEntry::where('user_id', $this->reader->id)->whereHas('entryType', fn ($c) => $c->where('notation', 'auto-collection'))->count());

        $this->actingAs($this->reader)->post(route('research.collections.step', [$collection, 'link']), ['url' => 'javascript:alert(1)', 'title' => 'X'])->assertSessionHasErrors('research');
        $this->actingAs($this->reader)->post(route('research.collections.step', [$collection, 'link']), ['url' => 'https://example.org/bridge', 'title' => 'Bridge history'])->assertSessionHasNoErrors();
        $this->actingAs($this->reader)->post(route('research.collections.step', [$collection, 'import']), ['iris' => $this->letter->iri."\n".$this->draft->iri."\nnot an iri"])
            ->assertSessionHas('status', 'No records were added.');
        $item = $collection->items()->where('subject_id', $this->letter->id)->sole();
        $this->actingAs($this->reader)->post(route('research.collections.item', [$item, 'update']), ['notes' => 'Dated 1898', 'tags' => 'bridge, river, bridge', 'sort_order' => 5])->assertSessionHasNoErrors();
        $this->assertSame('bridge, river', $item->fresh()->tags);

        $this->actingAs($this->reader)->get(route('research.collections.show', $collection))->assertOk()->assertSee('Letter about the parish bridge')->assertSee('Bridge history')->assertSee('Dated 1898');
        $this->actingAs($this->reader)->get(route('research.collections.finding-aid', $collection))->assertOk()->assertSee('RA PC/1')->assertSee('Letter about the parish bridge')->assertSee('RA PC/2')->assertDontSee('Bridge history');
        $this->actingAs($this->other)->get(route('research.collections.show', $collection))->assertNotFound();
        $this->actingAs($this->other)->post(route('research.collections.step', [$collection, 'delete']))->assertSessionHasErrors('research');

        $link = $this->actingAs($this->reader)->post(route('research.collections.step', [$collection, 'share']), ['share' => 1])->assertSessionHasNoErrors()->getSession()->get('share_link');
        $this->assertNotNull($link);
        auth()->logout();
        // A visitor of the link sees what the public may read: not the draft.
        $this->get($link)->assertOk()->assertSee('Letter about the parish bridge')->assertSee('Bridge history')->assertDontSee('Minutes not yet published');
        $this->actingAs($this->reader)->post(route('research.collections.step', [$collection, 'share']), ['share' => 0]);
        auth()->logout();
        $this->get($link)->assertNotFound();
    }

    public function test_annotations_point_into_a_record_and_are_read_by_who_they_are_shared_with(): void
    {
        $studio = fn (User $user, array $data) => $this->actingAs($user)->post(route('research.studio.save', ['records', $this->letter->id]), $data + ['annotation_type' => 'note', 'visibility' => 'private']);
        $studio($this->reader, ['content' => 'The bridge is drawn here.', 'selector_type' => 'fragment', 'selector_value' => 'xywh=10,20,300,150', 'tags' => 'bridge, drawing'])->assertSessionHasNoErrors();
        $studio($this->reader, ['content' => 'Bad', 'selector_type' => 'fragment', 'selector_value' => 'xywh=a,b'])->assertSessionHasErrors('research');
        $studio($this->reader, ['content' => 'Bad', 'selector_type' => 'svg', 'selector_value' => '<script>alert(1)</script>'])->assertSessionHasErrors('research');
        $studio($this->reader, ['content' => 'Signed by the clerk.', 'selector_type' => 'text-quote', 'exact' => 'the clerk', 'prefix' => 'signed by ', 'visibility' => 'public'])->assertSessionHasNoErrors();
        $studio($this->reader, ['title' => 'Shared with my group', 'visibility' => 'shared'])->assertSessionHasNoErrors();
        $studio($this->reader, [])->assertSessionHasErrors('research');
        $this->actingAs($this->reader)->post(route('research.studio.save', ['records', '00000000-0000-0000-0000-000000000000']), ['annotation_type' => 'note', 'visibility' => 'private', 'content' => 'x'])->assertNotFound();

        $mine = ResearchAnnotation::where('user_id', $this->reader->id)->with('targets')->orderBy('created_at')->get();
        $this->assertCount(3, $mine);
        $this->assertEquals(['type' => 'FragmentSelector', 'conformsTo' => 'http://www.w3.org/TR/media-frags/', 'value' => 'xywh=10,20,300,150'], $mine[0]->targets->sole()->selector);
        $this->assertSame('TextQuoteSelector', $mine[1]->targets->sole()->selector['type']);
        $this->assertSame(3, ResearchDiaryEntry::where('user_id', $this->reader->id)->whereHas('entryType', fn ($c) => $c->where('notation', 'auto-annotation'))->count());

        // Another account reads the public one only; once in a research workspace with the author, the shared one too.
        $this->actingAs($this->other)->get(route('research.studio', ['records', $this->letter->id]))->assertOk()->assertSee('Signed by the clerk.')
            ->assertDontSee('The bridge is drawn here.')->assertDontSee('Shared with my group');
        $workspace = $this->workspace($this->reader, 'Bridge group');
        $this->invite($this->reader, $workspace, $this->other, 'viewer', true);
        $this->actingAs($this->other)->get(route('research.studio', ['records', $this->letter->id]))->assertOk()->assertSee('Shared with my group')->assertDontSee('The bridge is drawn here.');

        // A record's image the public may see is offered to mark a region on; the annotation keeps its canvas and copy.
        $image = new \Imagick;
        $image->newImage(800, 600, new \ImagickPixel('#d8c9a3'));
        $image->setImageFormat('png');
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($path, $image->getImageBlob());
        $scan = app(InstantiationService::class)->upload($this->letter, $path, 'letter.png', 'en', 'Scan of the letter');
        app(PublicationService::class)->apply($scan, 'publish', null, false, $this->admin);
        $canvas = route('iiif.manifest', $this->letter->id).'/canvas/'.$scan->id;
        $this->actingAs($this->reader)->get(route('research.studio', ['records', $this->letter->id]))->assertOk()->assertSee($canvas, false)->assertSee('id="st-stage"', false);
        $studio($this->reader, ['annotation_type' => 'highlight', 'canvas_id' => $canvas, 'selector_type' => 'fragment', 'selector_value' => 'xywh=0,0,400,300'])->assertSessionHasNoErrors();
        $this->assertSame($scan->id, ResearchAnnotation::where('canvas_id', $canvas)->sole()->instantiation_id);
        $this->actingAs($this->other)->get(route('research.studio', ['records', $this->draft->id]))->assertOk()->assertDontSee('id="st-stage"', false);

        $page = $this->actingAs($this->reader)->getJson(route('research.studio.page', ['records', $this->letter->id]))->assertOk()->json();
        $this->assertSame('AnnotationPage', $page['type']);
        $this->assertCount(4, $page['items']);
        $region = collect($page['items'])->first(fn ($a) => ($a['target']['selector']['value'] ?? null) === 'xywh=10,20,300,150');
        $this->assertSame('commenting', $region['motivation']);
        $this->assertSame($this->letter->iri, $region['target']['source']);
        $this->assertContains(['type' => 'TextualBody', 'value' => 'drawing', 'purpose' => 'tagging'], $region['body']);

        $this->actingAs($this->other)->post(route('research.annotations.delete', $mine[0]))->assertSessionHasErrors('research');
        $this->actingAs($this->reader)->get(route('research.annotations.index', ['q' => 'bridge']))->assertOk()->assertSee('The bridge is drawn here.')->assertDontSee('Signed by the clerk.');
        $this->actingAs($this->reader)->post(route('research.annotations.delete', $mine[0]))->assertSessionHasNoErrors();
        $this->assertSame(3, ResearchAnnotation::where('user_id', $this->reader->id)->count());
    }

    public function test_a_notebook_gathers_notes_records_and_saved_searches(): void
    {
        $this->actingAs($this->reader)->post(route('research.notebooks.store'), ['title' => 'Bridge thesis', 'cover_iri' => $this->letter->iri])->assertSessionHasNoErrors();
        $notebook = ResearchNotebook::where('user_id', $this->reader->id)->sole();
        $this->assertSame($this->letter->id, $notebook->cover_record_id);
        $add = fn (array $data, ?User $user = null) => $this->actingAs($user ?? $this->reader)->post(route('research.notebooks.step', [$notebook, 'item']), $data);
        $add(['item_type' => 'note', 'body' => 'Start with the council minutes.'])->assertSessionHasNoErrors();
        $add(['item_type' => 'source-pin', 'iri' => $this->letter->iri, 'title' => 'The letter', 'pinned' => 1])->assertSessionHasNoErrors();
        $add(['item_type' => 'source-pin', 'iri' => 'https://archives.example.org/no-such-record'])->assertSessionHasErrors('research');
        $add(['item_type' => 'ai-output', 'body' => 'x'])->assertSessionHasErrors('research');
        $add(['item_type' => 'note'])->assertSessionHasErrors('research');
        $theirs = SavedSearch::create(['institution_id' => $this->institution->id, 'user_id' => $this->other->id, 'name' => 'Theirs', 'search_query' => 'bridge']);
        $add(['item_type' => 'saved-query', 'saved_search_id' => $theirs->id])->assertSessionHasErrors('research');
        $this->actingAs($this->reader)->post(route('research.searches.store'), ['name' => 'Bridges', 'search_query' => 'bridge'])->assertSessionHasNoErrors();
        $add(['item_type' => 'saved-query', 'saved_search_id' => SavedSearch::where('user_id', $this->reader->id)->value('id')])->assertSessionHasNoErrors();
        $add(['item_type' => 'note', 'body' => 'Not mine to add'], $this->other)->assertSessionHasErrors('research');

        $items = $notebook->fresh()->items()->with('itemType')->get();
        $this->assertSame(['source-pin', 'note', 'saved-query'], $items->pluck('itemType.notation')->all());
        $this->actingAs($this->reader)->get(route('research.notebooks.show', $notebook))->assertOk()->assertSee('The letter')->assertSee('Start with the council minutes.')->assertSee('Bridges');
        $this->actingAs($this->other)->get(route('research.notebooks.show', $notebook))->assertNotFound();
        $this->actingAs($this->reader)->post(route('research.notebooks.item', [$items[1], 'update']), ['body' => 'Start with the minutes of 1898.', 'pinned' => 1])->assertSessionHasNoErrors();
        $this->assertTrue($items[1]->fresh()->pinned);
        $this->actingAs($this->reader)->post(route('research.notebooks.step', [$notebook, 'delete']))->assertRedirect(route('research.notebooks.index'));
        $this->assertSame(0, ResearchNotebook::count());
    }

    public function test_the_research_diary_keeps_entries_the_researcher_writes_and_those_the_workspace_writes(): void
    {
        $this->actingAs($this->reader)->post(route('research.diary.store'), ['entry_date' => '2026-11-02', 'title' => 'Archive visit', 'content' => 'Read the minutes.',
            'entry_type' => 'manual', 'time_spent_minutes' => 95, 'tags' => 'minutes', 'is_private' => 1])->assertSessionHasNoErrors();
        $this->actingAs($this->reader)->post(route('research.diary.store'), ['entry_date' => '2026-11-03', 'content' => 'The bridge was built twice.', 'entry_type' => 'reflection',
            'time_spent_minutes' => 30, 'is_private' => 1])->assertSessionHasNoErrors();
        // A researcher cannot write an entry as the Workspace would.
        $this->actingAs($this->reader)->post(route('research.diary.store'), ['entry_date' => '2026-11-03', 'content' => 'Pretend', 'entry_type' => 'auto-booking'])->assertSessionHasNoErrors();
        $this->assertSame(['manual', 'manual', 'reflection'], ResearchDiaryEntry::with('entryType')->get()->pluck('entryType.notation')->sort()->values()->all());
        $this->actingAs($this->reader)->post(route('research.searches.store'), ['name' => 'Bridges', 'search_query' => 'bridge'])->assertSessionHasNoErrors();

        $this->actingAs($this->reader)->get(route('research.diary.index'))->assertOk()->assertSee('Archive visit')->assertSee('Saved the search &quot;Bridges&quot;', false)->assertSee('2 h 5 min');
        $this->actingAs($this->reader)->get(route('research.diary.index', ['type' => 'reflection']))->assertOk()->assertSee('The bridge was built twice.')->assertDontSee('Archive visit')->assertSee('0 h 30 min');
        $this->actingAs($this->reader)->get(route('research.diary.index', ['from' => '2026-11-03', 'q' => 'minutes']))->assertOk()->assertDontSee('Archive visit');

        $entry = ResearchDiaryEntry::where('title', 'Archive visit')->sole();
        $this->assertTrue($entry->is_private);
        $this->actingAs($this->other)->post(route('research.diary.delete', $entry))->assertSessionHasErrors('research');
        $this->actingAs($this->other)->post(route('research.diary.update', $entry), ['entry_date' => '2026-11-02', 'content' => 'Changed'])->assertSessionHasErrors('research');
        $this->actingAs($this->reader)->post(route('research.diary.update', $entry), ['entry_date' => '2026-11-02', 'title' => 'Archive visit', 'content' => 'Read the minutes of 1898.',
            'entry_type' => 'manual', 'time_spent_minutes' => 95])->assertSessionHasNoErrors();
        $this->assertFalse($entry->fresh()->is_private);
        $auto = ResearchDiaryEntry::whereHas('entryType', fn ($c) => $c->where('notation', 'auto-search'))->sole();
        $this->actingAs($this->reader)->post(route('research.diary.update', $auto), ['entry_date' => '2026-11-03', 'content' => 'Saved it', 'entry_type' => 'manual'])->assertSessionHasNoErrors();
        $this->assertSame('auto-search', $auto->fresh()->entryType->notation);

        // An entry not private is read by the members of the research workspaces its author belongs to.
        $workspace = $this->workspace($this->other, 'Bridge group');
        $this->actingAs($this->other)->get(route('research.workspaces.show', $workspace))->assertOk()->assertDontSee('Read the minutes of 1898.');
        $this->invite($this->other, $workspace, $this->reader, 'member', true);
        $this->actingAs($this->other)->get(route('research.workspaces.show', $workspace))->assertOk()->assertSee('Read the minutes of 1898.')->assertDontSee('The bridge was built twice.');
    }

    public function test_a_research_workspace_is_shared_by_invitation_with_roles_resources_and_files(): void
    {
        $workspace = $this->workspace($this->reader, 'Bridge group');
        $this->actingAs($this->other)->get(route('research.workspaces.show', $workspace))->assertNotFound();
        $this->actingAs($this->reader)->post(route('research.workspaces.step', [$workspace, 'invite']), ['email' => 'nobody@example.org', 'role' => 'viewer'])->assertSessionHasErrors('research');
        $this->actingAs($this->reader)->post(route('research.workspaces.step', [$workspace, 'invite']), ['email' => 'OLU@example.org', 'role' => 'owner'])->assertSessionHasErrors('research');
        $member = $this->invite($this->reader, $workspace, $this->other, 'viewer', false);
        Mail::assertQueued(ResearchMail::class, fn ($m) => $m->hasTo('olu@example.org'));
        $this->actingAs($this->reader)->post(route('research.workspaces.step', [$workspace, 'invite']), ['email' => 'olu@example.org', 'role' => 'viewer'])->assertSessionHasErrors('research');
        $this->actingAs($this->other)->get(route('research.workspaces.index'))->assertOk()->assertSee('Rita invites you to &quot;Bridge group&quot; as Viewer.', false);
        $this->actingAs($this->admin)->post(route('research.workspaces.member', [$member, 'accept']))->assertSessionHasErrors('research');
        $this->actingAs($this->other)->post(route('research.workspaces.member', [$member, 'accept']))->assertSessionHasNoErrors();
        $this->actingAs($this->other)->get(route('research.workspaces.show', $workspace))->assertOk()->assertSee('Bridge group');

        // A viewer reads; a contributor adds what is theirs.
        $this->actingAs($this->other)->post(route('research.collections.store'), ['name' => 'Olu sources'])->assertSessionHasNoErrors();
        $theirs = ResearchCollection::where('user_id', $this->other->id)->sole();
        app(Collections::class)->add($this->other, $theirs, $this->letter);
        $this->actingAs($this->other)->post(route('research.workspaces.step', [$workspace, 'resource']), ['resource_type' => 'collection', 'resource_id' => $theirs->id])->assertSessionHasErrors('research');
        $this->actingAs($this->other)->post(route('research.workspaces.member', [$member, 'role']), ['role' => 'admin'])->assertSessionHasErrors('research');
        $this->actingAs($this->reader)->post(route('research.workspaces.member', [$member, 'role']), ['role' => 'contributor'])->assertSessionHasNoErrors();
        $this->actingAs($this->other)->post(route('research.workspaces.step', [$workspace, 'resource']), ['resource_type' => 'collection', 'resource_id' => $theirs->id])->assertSessionHasNoErrors();
        $mineToo = ResearchCollection::create(['institution_id' => $this->institution->id, 'user_id' => $this->reader->id, 'name' => 'Rita private']);
        $this->actingAs($this->other)->post(route('research.workspaces.step', [$workspace, 'resource']), ['resource_type' => 'collection', 'resource_id' => $mineToo->id])->assertSessionHasErrors('research');
        $this->actingAs($this->other)->post(route('research.workspaces.step', [$workspace, 'resource']), ['resource_type' => 'link', 'external_url' => 'https://example.org/bridge', 'title' => 'Bridge history'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->other)->post(route('research.workspaces.step', [$workspace, 'file']), ['file' => UploadedFile::fake()->createWithContent('notes.txt', 'Bridge notes')])
            ->assertSessionHasNoErrors();

        $workspace->refresh();
        $resource = $workspace->resources()->whereNotNull('resource_id')->sole();
        $this->actingAs($this->reader)->get(route('research.workspaces.resource', $resource))->assertOk()->assertSee('Olu sources')->assertSee('Letter about the parish bridge');
        $file = $workspace->files()->sole();
        $this->assertSame('Bridge notes', $this->actingAs($this->reader)->get(route('research.workspaces.file', $file))->assertOk()->streamedContent());
        $this->actingAs($this->admin)->get(route('research.workspaces.file', $file))->assertNotFound();
        $this->actingAs($this->admin)->get(route('research.workspaces.resource', $resource))->assertNotFound();

        // A contributor removes only what they added; the owner cannot leave, but may hand the workspace over.
        $mine = $workspace->resources()->create(['resource_type_id' => Concept::where('iri', ResearchWorkspaces::RESOURCES.'link')->value('id'),
            'external_url' => 'https://example.org/rita', 'title' => 'Rita link', 'added_by_id' => $this->reader->id, 'added_at' => now()]);
        $this->actingAs($this->other)->post(route('research.workspaces.resource.remove', $mine))->assertSessionHasErrors('research');
        $this->actingAs($this->other)->post(route('research.workspaces.resource.remove', $resource))->assertSessionHasNoErrors();
        $owner = $workspace->members()->where('user_id', $this->reader->id)->sole();
        $this->actingAs($this->reader)->post(route('research.workspaces.member', [$owner, 'remove']))->assertSessionHasErrors('research');
        $this->actingAs($this->reader)->post(route('research.workspaces.member', [$member, 'role']), ['role' => 'owner'])->assertSessionHasNoErrors();
        $this->assertSame($this->other->id, $workspace->fresh()->owner_id);
        $this->assertSame('admin', $owner->fresh()->role->notation);
        $this->actingAs($this->reader)->post(route('research.workspaces.step', [$workspace, 'delete']))->assertSessionHasErrors('research');
        $this->actingAs($this->reader)->post(route('research.workspaces.member', [$owner->fresh(), 'remove']))->assertRedirect(route('research.workspaces.index'));
        $this->actingAs($this->reader)->get(route('research.workspaces.show', $workspace))->assertNotFound();

        // A public workspace is read by the institution, but not changed.
        $this->actingAs($this->other)->post(route('research.workspaces.step', [$workspace, 'update']), ['name' => 'Bridge group', 'visibility' => 'public'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->get(route('research.workspaces.show', $workspace))->assertOk()->assertDontSee('Add a resource');
        $this->actingAs($this->admin)->post(route('research.workspaces.step', [$workspace, 'file']), ['file' => UploadedFile::fake()->createWithContent('x.txt', 'x')])->assertSessionHasErrors('research');
        $this->actingAs($this->other)->post(route('research.workspaces.step', [$workspace, 'delete']))->assertRedirect(route('research.workspaces.index'));
        $this->assertSame(0, ResearchWorkspace::count());
    }

    private function workspace(User $owner, string $name): ResearchWorkspace
    {
        $this->actingAs($owner)->post(route('research.workspaces.store'), ['name' => $name, 'visibility' => 'members'])->assertSessionHasNoErrors();

        return ResearchWorkspace::where(['owner_id' => $owner->id, 'name' => $name])->sole();
    }

    private function invite(User $by, ResearchWorkspace $workspace, User $who, string $role, bool $accept): ResearchWorkspaceMember
    {
        $this->actingAs($by)->post(route('research.workspaces.step', [$workspace, 'invite']), ['email' => $who->email, 'role' => $role])->assertSessionHasNoErrors();
        $member = ResearchWorkspaceMember::where(['workspace_id' => $workspace->id, 'user_id' => $who->id])->sole();
        if ($accept) {
            $this->actingAs($who)->post(route('research.workspaces.member', [$member, 'accept']))->assertSessionHasNoErrors();
        }

        return $member;
    }
}
