<?php

// OpenRiC Workspace - display profiles, browse settings and the code reference (roadmap phase 20d), after Heratio's
// ahg-display and ahg-functions-docs: a unit's public page shows the elements its level's profile names, in its order and
// under its labels, offers only the actions it allows and leaves the hierarchy out where it says so, while a level
// without a profile shows everything; the staff browse lists as the institution sets it, or as an account sets its own
// where the institution allows it, sorts by date either way, and prints and downloads the same list; and the code
// reference lists the Workspace's own classes, routes and vocabularies, for who administers it.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\DateService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\DisplayProfile;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DisplayTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private Institution $institution;

    private User $admin;

    private User $editor;

    private RecordResource $fonds;

    private RecordResource $letter;

    private RecordResource $map;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://riverside.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->admin = app(AccountService::class)->create($this->institution, 'Admin', 'admin@riverside.example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->admin, 'administrator');
        $this->editor = app(AccountService::class)->create($this->institution, 'Editor', 'editor@riverside.example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->editor, 'editor');
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $records = app(RecordService::class);
        $this->fonds = $records->create($this->institution, self::LEVEL.'fonds', 'Riverside Parish Council records', 'en', null, $holder, 'RPC');
        $this->letter = $records->create($this->institution, self::LEVEL.'item', 'Letter of 3 May 1902', 'en', $this->fonds, null, '1');
        $this->map = $records->create($this->institution, self::LEVEL.'item', 'A map of the parish', 'en', $this->fonds, null, '2');
        $records->describe($this->letter, ['scope-and-content' => 'Names the witness at the marriage.', 'extent' => 'One leaf.', 'archival-history' => 'Kept by the clerk.'], 'en');
        $records->describe($this->fonds, ['scope-and-content' => 'Minutes and letters of the council.', 'archival-history' => 'Transferred in 1950.'], 'en');
        app(DateService::class)->add($this->letter, ['property' => 'creation', 'normalized' => '1902-05-03']);
        app(DateService::class)->add($this->map, ['property' => 'creation', 'normalized' => '1880']);
        foreach ([$holder, $this->fonds, $this->letter, $this->map] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->admin);
        }
    }

    public function test_a_level_profile_chooses_the_elements_order_labels_and_actions_of_the_public_page(): void
    {
        $this->actingAs($this->editor)->get(route('display.index'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('display.create'))->assertOk()->assertSee('scope-and-content', false);
        $this->actingAs($this->admin)->post(route('display.store'), ['name' => 'Items', 'level' => 'item', 'elements' => ['scope-and-content', 'extent'],
            'order' => ['extent' => 1, 'scope-and-content' => 2], 'labels' => ['extent' => ['en' => 'How much there is']], 'show_tree' => 0, 'actions' => ['cite']])->assertRedirect();
        $profile = DisplayProfile::sole();
        $this->assertSame(['extent', 'scope-and-content'], $profile->elements);
        auth()->logout();

        $page = $this->get(route('discover.record', $this->letter->id))->assertOk();
        $page->assertSeeInOrder(['How much there is', 'One leaf.', 'Names the witness at the marriage.'])->assertDontSee('Kept by the clerk.');
        $page->assertSee(route('cite', $this->letter->id), false)->assertDontSee(route('feedback.create', ['record' => $this->letter->id]), false);
        $page->assertDontSee('class="tree-aside"', false);

        // The fonds has no profile of its level, and the institution no default: it shows everything, as before.
        $this->get(route('discover.record', $this->fonds->id))->assertOk()->assertSee('Transferred in 1950.')->assertSee(route('feedback.create', ['record' => $this->fonds->id]), false);

        $this->actingAs($this->admin)->post(route('display.store'), ['name' => 'Again', 'level' => 'item'])->assertSessionHasErrors('level');
        $this->actingAs($this->admin)->post(route('display.destroy', $profile))->assertRedirect(route('display.index'));
        auth()->logout();
        $this->get(route('discover.record', $this->letter->id))->assertOk()->assertSee('Kept by the clerk.');
    }

    public function test_staff_browse_as_the_institution_sets_or_as_they_set_themselves(): void
    {
        $this->actingAs($this->admin)->post(route('display.browse'), ['view' => 'table', 'sort' => 'start-date', 'direction' => 'ascending', 'per_page' => 10, 'show_thumbnails' => 0, 'allow_user_override' => 1])->assertRedirect();
        $this->actingAs($this->editor)->get(route('records.index', ['top' => 0]))->assertOk()->assertSee('<table>', false)
            ->assertSeeInOrder(['A map of the parish', 'Letter of 3 May 1902']);

        $this->actingAs($this->editor)->post(route('records.browse-settings'), ['view' => 'list', 'sort' => 'end-date', 'direction' => 'descending', 'per_page' => 20])->assertRedirect(route('records.index'));
        $this->actingAs($this->editor)->get(route('records.index', ['top' => 0]))->assertOk()->assertDontSee('<table>', false)
            ->assertSeeInOrder(['Letter of 3 May 1902', 'A map of the parish'])->assertSee('Browse as the institution does');
        $this->actingAs($this->admin)->get(route('records.index', ['top' => 0]))->assertSee('<table>', false);

        // Once the institution takes the choice away, the account's own settings no longer count.
        $this->actingAs($this->admin)->post(route('display.browse'), ['view' => 'table', 'sort' => 'title', 'direction' => 'ascending', 'per_page' => 10, 'allow_user_override' => 0])->assertRedirect();
        $this->actingAs($this->editor)->get(route('records.index', ['top' => 0]))->assertSee('<table>', false)->assertDontSee('How I browse');
        $this->actingAs($this->editor)->post(route('records.browse-settings'), ['view' => 'list', 'sort' => 'title', 'direction' => 'ascending', 'per_page' => 20])->assertSessionHasErrors('view');
    }

    public function test_the_browse_list_prints_and_downloads_as_filtered_and_sorted(): void
    {
        $this->actingAs($this->editor)->get(route('records.index', ['top' => 0, 'sort' => 'start-date', 'direction' => 'descending', 'format' => 'print']))->assertOk()
            ->assertSeeInOrder(['Letter of 3 May 1902', 'A map of the parish']);
        $csv = $this->actingAs($this->editor)->get(route('records.index', ['q' => 'map', 'format' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringStartsWith("\u{FEFF}IRI,\"Reference code\",Title", $csv);
        $this->assertStringContainsString('A map of the parish', $csv);
        $this->assertStringNotContainsString('Letter of 3 May 1902', $csv);
    }

    public function test_the_code_reference_is_read_from_the_code_for_who_administers_it(): void
    {
        $this->actingAs($this->editor)->get(route('functions-docs.index'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('functions-docs.index'))->assertOk()->assertSee('PHP classes and methods');
        $this->actingAs($this->admin)->get(route('functions-docs.kind', ['php', 'q' => 'Displays']))->assertOk()
            ->assertSee('saveProfile')->assertSee('app/Display/Displays.php', false)->assertSee('display profiles and browse settings');
        $this->actingAs($this->admin)->get(route('functions-docs.kind', ['routes', 'q' => 'dropdowns']))->assertOk()->assertSee('dropdowns.edit')->assertSee('GET /admin/dropdowns/{vocabulary}', false);
        $this->actingAs($this->admin)->get(route('functions-docs.kind', ['vocabularies', 'q' => 'browse-sort']))->assertOk()->assertSee('5 terms');
        $this->actingAs($this->admin)->get(route('functions-docs.kind', 'nothing'))->assertNotFound();
    }
}
