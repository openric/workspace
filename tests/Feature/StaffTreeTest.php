<?php

// OpenRiC Workspace - the staff side of a large fonds (openric/workspace#8): a record's units inside, a hundred to a page,
// and its tree and hierarchy, show only what the account may read, read until a page is full rather than all at once.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\AccessControl;
use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Description\TreeService;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\Permission;
use App\Models\RecordResource;
use App\Models\User;
use App\Support\Vocab;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffTreeTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private Institution $institution;

    private User $archivist;

    private RecordResource $fonds;

    /** @var list<RecordResource> */
    private array $items = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->archivist = app(AccountService::class)->create($this->institution, 'Archivist', 'archivist@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->archivist, 'editor');
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $records = app(RecordService::class);
        $this->fonds = $records->create($this->institution, self::LEVEL.'fonds', 'Minutes of the council', 'en', null, $holder, 'MIN');
        foreach (range(1, 12) as $n) {
            $this->items[] = $records->create($this->institution, self::LEVEL.'item', "Minute book $n", 'en', $this->fonds, null, (string) $n);
        }
    }

    /** The account's role refused reading one unit, as an institution closes one file to a role. */
    private function hide(RecordResource $unit): void
    {
        Permission::create(['institution_id' => $this->institution->id, 'role_id' => app(RoleService::class)->byTemplate($this->institution, 'editor')->id,
            'kind_id' => Concept::where('iri', Vocab::kind('description'))->value('id'), 'action_id' => Concept::where('iri', Vocab::permission('read'))->value('id'),
            'subject_table' => 'record_resources', 'subject_id' => $unit->id, 'is_grant' => false]);
        app(AccessControl::class)->flush();
    }

    public function test_the_first_readable_units_are_found_skipping_what_the_account_may_not_read(): void
    {
        $this->hide($this->items[1]);
        $query = fn () => RecordResource::where('parent_id', $this->fonds->id)->orderBy('position')->orderBy('id');
        $may = fn (RecordResource $u) => $this->archivist->may('read', 'description', $u);
        [$page, $more] = RecordService::firstReadable($query(), $may, 5);
        $this->assertSame(['1', '3', '4', '5', '6'], $page->map->identifier->all(), 'the refused one is skipped, not counted');
        $this->assertTrue($more);
        [$page, $more] = RecordService::firstReadable($query(), $may, 5, 10);
        $this->assertSame(['12'], $page->map->identifier->all());
        $this->assertFalse($more);
    }

    public function test_a_branch_and_a_units_siblings_show_only_what_the_account_may_read_a_page_at_a_time(): void
    {
        $this->hide($this->items[0]);
        $tree = new TreeService($this->archivist);
        $first = $tree->children($this->fonds->id);
        $this->assertSame(array_map(fn ($n) => "Minute book $n", range(2, 11)), array_column($first['items'], 'title'));
        $this->assertTrue($first['more']);
        $this->assertSame(['Minute book 12'], array_column($tree->children($this->fonds->id, TreeService::PAGE)['items'], 'title'));
        $this->assertFalse($tree->children($this->fonds->id, TreeService::PAGE)['more']);
        $this->assertTrue($tree->node($this->fonds)['hasChildren']);

        // A unit with nothing inside it shows its readable siblings either side.
        $window = $tree->window($this->items[5]);
        $this->assertSame(['Minute book 2', 'Minute book 3', 'Minute book 4', 'Minute book 5'], array_column($window['before'], 'title'));
        $this->assertSame(array_map(fn ($n) => "Minute book $n", range(7, 12)), array_column($window['after'], 'title'));
        $this->assertFalse($window['moreBefore']);
        $this->assertFalse($window['moreAfter']);
    }

    public function test_a_records_units_inside_come_a_hundred_to_a_page_and_its_hierarchy_leaves_out_what_is_refused(): void
    {
        $records = app(RecordService::class);
        foreach (range(13, RecordService::INSIDE_PAGE + 2) as $n) {
            $records->create($this->institution, self::LEVEL.'item', "Minute book $n", 'en', $this->fonds, null, (string) $n);
        }
        $this->hide($this->items[2]);
        $first = $this->actingAs($this->archivist)->get(route('records.show', $this->fonds))->assertOk()
            ->assertSee('Minute book 1<', false)->assertDontSee('Minute book 3<', false)->assertSee(route('records.show', [$this->fonds, 'inside' => 2]), false);
        $this->assertSame(2 * RecordService::INSIDE_PAGE, substr_count((string) $first->getContent(), 'aria-label="Move Minute book'), 'a page is a hundred readable units, each with its Up and Down');
        $this->actingAs($this->archivist)->get(route('records.show', [$this->fonds, 'inside' => 2]))->assertOk()
            ->assertSee('Minute book '.(RecordService::INSIDE_PAGE + 2).'<', false)->assertSee(route('records.show', [$this->fonds, 'inside' => 1]), false);

        $this->actingAs($this->archivist)->get(route('records.tree', $this->fonds))->assertOk()
            ->assertSee('Minute book 12</a> <small>Item, 12</small>', false)->assertDontSee('Minute book 3</a>', false);
    }

    public function test_the_hierarchy_is_depth_first_and_a_refused_unit_hides_what_is_inside_it(): void
    {
        $records = app(RecordService::class);
        $series = $records->create($this->institution, self::LEVEL.'series', 'Correspondence', 'en', $this->fonds, null, 'C');
        $letter = $records->create($this->institution, self::LEVEL.'item', 'Letter to the mayor', 'en', $series, null, 'C/1');
        $outline = $records->outline($this->fonds, $this->archivist, ['en']);
        $titles = array_map(fn ($row) => $outline['titles'][$row['unit']->id].'@'.$row['depth'], $outline['units']);
        $this->assertSame(['Correspondence@1', 'Letter to the mayor@2'], array_slice($titles, -2), 'the series, then what is inside it, one deeper');
        $this->assertFalse($outline['more']);

        $this->hide($series);
        $this->actingAs($this->archivist)->get(route('records.tree', $this->fonds))->assertOk()
            ->assertDontSee('Correspondence')->assertDontSee('Letter to the mayor')->assertSee('Minute book 12');
    }
}
