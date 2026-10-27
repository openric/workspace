<?php

// OpenRiC Workspace - full-text search (WS-016, roadmap phase 6, ADR 0009): what the public may read only, stemming
// and accents, the query syntax, facets, a range of years, highlighting that cannot inject markup, and an index that
// follows every change.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\DateService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use App\Search\SearchIndex;
use App\Search\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function search(string $query, array $filters = [], bool $public = true): array
    {
        return app(SearchService::class)->search($this->institution, $query, $public, $filters);
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
}
