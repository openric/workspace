<?php

// OpenRiC Workspace - full-text search (WS-016, roadmap phase 6, ADR 0009): what the public may read only, stemming
// and accents, the query syntax, facets, a range of years, highlighting that cannot inject markup, and an index that
// follows every change; staff search over drafts the account may see, and the search statistics.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\AccessControl;
use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\DateService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\Permission;
use App\Models\RecordResource;
use App\Models\User;
use App\Search\SearchIndex;
use App\Search\SearchService;
use App\Support\Vocab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://archives.example.org';

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private Institution $institution;

    private User $editor;

    private RecordResource $minutes;

    private RecordResource $photos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => self::SITE]);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->editor = app(AccountService::class)->create($this->institution, 'Editor', 'editor@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->editor, 'editor');
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $records = app(RecordService::class);
        $this->minutes = $records->create($this->institution, self::LEVEL.'series', 'Minutes of the town council', 'en', null, $holder, 'MIN');
        $records->describe($this->minutes, ['scope-and-content' => 'Minutes of the meetings held by the council, with the reports of its committees on roads and the bridge.'], 'en', $this->editor);
        $this->photos = $records->create($this->institution, self::LEVEL.'item', 'Photographs of the bridge', 'en', null, $holder, 'PH');
        $records->describe($this->photos, ['scope-and-content' => 'Dossiers de l\'étude du pont, <b>photographiés</b> en 1902.'], 'en', $this->editor);
        app(DateService::class)->add($this->photos, ['property' => 'creation', 'normalized' => '1902'], $this->editor);
        app(DateService::class)->add($this->minutes, ['property' => 'creation', 'normalized' => '1950/1975'], $this->editor);
        foreach ([$holder, $this->minutes, $this->photos] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->editor);
        }
        // A draft value written after publishing: in the staff index only.
        $records->describe($this->minutes, ['archival-history' => 'Kept in the strongroom of the old courthouse.'], 'en', $this->editor);
        app(SearchIndex::class)->flush();
    }

    private function search(string $query, array $filters = [], bool $public = true, string $match = 'words'): array
    {
        return app(SearchService::class)->search($this->institution, $query, $public, $filters, 1, 20, ['match' => $match]);
    }

    public function test_a_search_that_finds_nothing_shows_the_closest_titles_said_to_be_close(): void
    {
        $found = $this->search('Minuets');
        $this->assertTrue($found['close']);
        $this->assertSame(['Minutes of the town council'], array_column($found['results'], 'title'));
        $this->assertFalse($this->search('Minutes')['close'], 'an exact match is not a close one');
        $this->assertSame(['total' => 0, 'close' => false], array_intersect_key($this->search('zzqxv'), ['total' => 1, 'close' => 1]));
        // Still only what the public may read: the draft-only staff index is not asked.
        $this->assertSame(0, $this->search('Strongrom')['total']);

        $this->get(route('discover.home', ['q' => 'Minuets']))->assertOk()->assertSee('the closest matches by title')->assertSee('Minutes of the town council');
        $this->assertSame(0, (int) DB::table('search_queries')->where('query', 'Minuets')->value('result_count'), 'counted as a search that found nothing');
    }

    public function test_a_regular_expression_searches_the_same_entries_and_a_bad_one_is_explained(): void
    {
        $this->assertSame(['Minutes of the town council'], array_column($this->search('^minutes of', match: 'pattern')['results'], 'title'), 'without regard to case');
        $this->assertSame(['Photographs of the bridge'], array_column($this->search('19[0-4][0-9]', match: 'pattern')['results'], 'title'));
        $this->assertStringContainsString('<mark>1902</mark>', (string) $this->search('19[0-4][0-9]', match: 'pattern')['results'][0]['snippet']);
        $this->assertSame(0, $this->search('court.ouse', match: 'pattern')['total'], 'a draft value is not public');
        $this->assertSame(1, $this->search('court.ouse', public: false, match: 'pattern')['total']);

        $bad = $this->search('(unclosed', match: 'pattern');
        $this->assertSame(0, $bad['total']);
        $this->assertStringContainsString('not a regular expression', (string) $bad['error']);
        $this->assertSame(1, $this->search('committees')['total'], 'the connection is still usable after a refused pattern');
        $this->get(route('discover.home', ['q' => '(unclosed', 'match' => 'pattern']))->assertOk()->assertSee('not a regular expression');
    }

    public function test_the_public_finds_only_what_is_published(): void
    {
        $this->assertSame(['Minutes of the town council'], array_column($this->search('committees')['results'], 'title'));
        $this->assertSame(0, $this->search('courthouse')['total'], 'a draft value is not public');
        $this->assertSame(1, $this->search('courthouse', public: false)['total'], 'staff find the draft');
    }

    public function test_stemming_accents_and_the_query_syntax(): void
    {
        $this->assertSame(['Minutes of the town council'], array_column($this->search('meeting')['results'], 'title'), 'meeting finds meetings');
        $this->assertSame(['Photographs of the bridge'], array_column($this->search('etude')['results'], 'title'), 'étude without its accent');
        $this->assertSame(2, $this->search('bridge')['total']);
        $this->assertSame(['Photographs of the bridge'], array_column($this->search('bridge -committees')['results'], 'title'));
        $this->assertSame(['Minutes of the town council'], array_column($this->search('"town council"')['results'], 'title'));
        $this->assertSame(0, $this->search('"council town"')['total']);
        $this->assertSame(2, $this->search('committees OR photographs')['total']);
    }

    public function test_facets_years_and_safe_highlighting(): void
    {
        $result = $this->search('bridge');
        $this->assertSame(['records' => 2], $result['facets']['kind']);
        $this->assertCount(2, $result['facets']['level']);
        $this->assertSame(1, $this->search('bridge', ['level' => $this->photos->level_id])['total']);
        $this->assertSame(['Photographs of the bridge'], array_column($this->search('bridge', ['to' => 1920])['results'], 'title'));
        $this->assertSame(['Minutes of the town council'], array_column($this->search('bridge', ['from' => 1940])['results'], 'title'));

        $snippet = (string) $this->search('photographiés')['results'][0]['snippet'];
        $this->assertStringContainsString('<mark>', $snippet);
        // No markup from the catalogue reaches the page: PostgreSQL's parser drops tags, and the rest is escaped.
        $this->assertStringNotContainsString('<', str_replace(['<mark>', '</mark>'], '', $snippet));
    }

    public function test_the_catalogue_page_and_an_index_that_follows_changes(): void
    {
        $this->get(self::SITE.'/discover?q=committees')->assertOk()->assertSee('Minutes of the town council')->assertSee('<mark>committees</mark>', false)
            ->assertSee('1 found.');
        $this->get(self::SITE.'/discover?q=bridge&to=1920')->assertOk()->assertSee('Photographs of the bridge')->assertDontSee('Minutes of the town council');

        // Withdrawn through the screens: gone from the public index at the end of the request.
        $this->actingAs($this->editor)->post(self::SITE."/records/{$this->photos->id}/publication", ['action' => 'withdraw']);
        $this->assertSame(0, $this->search('etude')['total']);
    }

    public function test_a_repository_whose_name_is_not_published_is_no_public_facet_though_its_records_are_found(): void
    {
        $family = app(RepositoryService::class)->create($this->institution, 'Engelbrecht Family Repository', 'en', 'EFR');
        $programmes = app(RecordService::class)->create($this->institution, self::LEVEL.'series', 'Programmes of the bridge choir', 'en', null, $family, 'CHO');
        app(PublicationService::class)->apply($programmes, 'publish', null, false, $this->editor);
        app(SearchIndex::class)->flush();
        $page = $this->get(self::SITE.'/discover?q=bridge')->assertOk()->assertSee('Programmes of the bridge choir');
        $page->assertSee('repository='.$this->minutes->repository_id, false)->assertDontSee('repository='.$family->id, false);
        $page->assertDontSee('Engelbrecht Family Repository');
    }

    public function test_staff_search_shows_drafts_only_to_those_who_may_see_them(): void
    {
        $this->actingAs($this->editor)->get(self::SITE.'/search?q=courthouse')->assertOk()
            ->assertSee('Minutes of the town council')->assertSee('Drafts are included.')->assertSee('<mark>courthouse</mark>', false);
        $reader = app(AccountService::class)->create($this->institution, 'Reader', 'reader@example.org', 'correct horse battery staple');
        $this->actingAs($reader)->get(self::SITE.'/search?q=courthouse')->assertOk()
            ->assertSee('Published values only.')->assertSee('No results found.')->assertDontSee('Minutes of the town council');
        $this->actingAs($reader)->get(self::SITE.'/search?q=committees')->assertOk()->assertSee('Minutes of the town council');
    }

    public function test_a_staff_search_page_is_full_though_the_account_may_not_read_a_result(): void
    {
        $records = app(RecordService::class);
        $ledgers = collect(range(1, 22))->map(fn ($n) => $records->create($this->institution, self::LEVEL.'item', sprintf('Rates ledger %02d', $n), 'en', $this->minutes, null, "L$n"));
        app(SearchIndex::class)->flush();
        // The editor's role refused reading one of them.
        Permission::create(['institution_id' => $this->institution->id, 'role_id' => app(RoleService::class)->byTemplate($this->institution, 'editor')->id,
            'kind_id' => Concept::where('iri', Vocab::kind('description'))->value('id'), 'action_id' => Concept::where('iri', Vocab::permission('read'))->value('id'),
            'subject_table' => 'record_resources', 'subject_id' => $ledgers[2]->id, 'is_grant' => false]);
        app(AccessControl::class)->flush();
        $before = DB::table('search_queries')->count();
        $first = (string) $this->actingAs($this->editor)->get(self::SITE.'/search?q=ledger')->assertOk()->getContent();
        $this->assertSame(20, substr_count($first, '>Rates ledger '), 'a full page of what the editor may read (#8)');
        $this->assertStringNotContainsString('Rates ledger 03', $first);
        $second = (string) $this->actingAs($this->editor)->get(self::SITE.'/search?q=ledger&page=2')->assertOk()->getContent();
        $this->assertSame(1, substr_count($second, '>Rates ledger '), 'and the one left on the next');
        $this->assertSame($before + 2, DB::table('search_queries')->count(), 'each page one search in the statistics');
    }

    public function test_search_statistics_count_queries_without_keeping_who_searched(): void
    {
        $this->search('bridge');
        $this->search('bridge');
        $this->search('zeppelin');
        $this->search('courthouse', public: false);
        $this->assertSame(4, DB::table('search_queries')->count());
        $this->assertNotContains('user_id', Schema::getColumnListing('search_queries'));

        $this->actingAs($this->editor)->get(self::SITE.'/search/statistics')->assertForbidden();
        $admin = app(AccountService::class)->create($this->institution, 'Admin', 'admin@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($admin, 'administrator');
        $this->actingAs($admin)->get(self::SITE.'/search/statistics')->assertOk()
            ->assertSeeInOrder(['Searched most', 'bridge', '2', 'Found nothing', 'zeppelin'])
            ->assertSee('4 searches, 3 different, 1 found nothing; 3 in the public catalogue.');
    }
}
