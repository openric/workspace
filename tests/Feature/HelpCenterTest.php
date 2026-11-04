<?php

// OpenRiC Workspace - the Help Center (roadmap phase 20b): guides read in from Markdown with their headers, read in again
// when their files change and removed with them, their HTML escaped, searched with their sections, guides for staff
// kept from the public, the guide for the screen in hand found by its route, guides linked by their text and by hand
// (and a link by hand kept when they are read in again), the system map and breakdown linking only to guides that
// exist; and the guides that ship with the Workspace all reading in, in known categories, every link between them and
// from the map resolving.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Help\HelpLibrary;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HelpCenterTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        $institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://riverside.example.org']);
        app(RoleService::class)->seedDefaults($institution);
        $this->admin = app(AccountService::class)->create($institution, 'Admin', 'admin@riverside.example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->admin, 'administrator');
        $this->dir = sys_get_temp_dir().'/workspace-help-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
        config(['workspace.help_directory' => $this->dir]);
        $this->guide('fixity', "title: Checking fixity\ncategory: Preservation\norder: 20\nroutes: fixity.*, files.show",
            "# Checking fixity\n\nA checksum is taken again.\n\n## Schedules\n\nSchedules run daily.\n\n## Failures\n\nA failed file is flagged. See [the store](/help/article/store).\n\n<script>alert(1)</script>");
        $this->guide('store', "title: The object store\ncategory: Preservation\norder: 30\nstaff: true", "# The object store\n\nOCFL keeps every version.");
        $this->guide('searching', "title: Searching\ncategory: Search and discovery\nroutes: search", "# Searching\n\nType words into the box.");
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_guides_are_read_in_searched_and_kept_from_the_public_where_for_staff(): void
    {
        $this->get(route('help.index'))->assertOk()->assertSee('Preservation')->assertSee('Search and discovery');
        $this->assertSame(3, DB::table('help_articles')->count());

        $page = $this->get(route('help.article', 'fixity'))->assertOk()->assertSee('Checking fixity')->assertSee('id="schedules"', false)
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
        $page->assertDontSee('The object store', false);
        $this->get(route('help.article', 'store'))->assertNotFound();
        $this->get(route('help.category', 'preservation'))->assertOk()->assertSee('Checking fixity')->assertDontSee('The object store');

        $this->actingAs($this->admin)->get(route('help.article', 'store'))->assertOk()->assertSee('The object store');
        $this->get(route('help.article', 'fixity'))->assertSee('The object store')->assertSee(__('Next: :title', ['title' => 'The object store']));
        $this->get(route('help.search', ['q' => 'flagged']))->assertOk()->assertSee('Checking fixity')->assertSee('#failures', false);
        auth()->logout();
        $this->get(route('help.search', ['q' => 'OCFL']))->assertOk()->assertDontSee('The object store');
    }

    public function test_the_library_follows_its_files(): void
    {
        $library = app(HelpLibrary::class);
        $library->ensureCurrent();
        $fixity = DB::table('help_articles')->where('slug', 'fixity')->value('id');
        $searching = DB::table('help_articles')->where('slug', 'searching')->value('id');
        $library->link($fixity, $searching);

        sleep(1);
        $this->guide('fixity', "title: Fixity, again\ncategory: Preservation\nroutes: fixity.*", '# Fixity, again');
        unlink($this->dir.'/store.md');
        $this->get(route('help.index'))->assertOk();
        $this->assertSame('Fixity, again', DB::table('help_articles')->where('slug', 'fixity')->value('title'));
        $this->assertSame($fixity, DB::table('help_articles')->where('slug', 'fixity')->value('id'), 'the same article, changed');
        $this->assertFalse(DB::table('help_articles')->where('slug', 'store')->exists(), 'a removed file takes its guide');
        $this->assertTrue(DB::table('help_article_links')->where(['help_article_id' => $fixity, 'related_article_id' => $searching, 'manual' => true])->exists(), 'a link by hand is kept');
    }

    public function test_help_for_the_screen_in_hand(): void
    {
        $this->get(route('help.context', ['route' => 'fixity.schedules']))->assertRedirect(route('help.article', 'fixity'));
        $this->get(route('help.context', ['route' => 'search']))->assertRedirect(route('help.article', 'searching'));
        $this->get(route('help.context', ['route' => 'records.show']))->assertRedirect(route('help.index'));
        $this->actingAs($this->admin)->get(route('home'))->assertSee(route('help.context', ['route' => 'home']), false);
    }

    public function test_guides_are_linked_by_hand_by_who_administers(): void
    {
        $this->actingAs($this->admin)->get(route('help.links', 'searching'))->assertOk();
        $target = DB::table('help_articles')->where('slug', 'fixity')->value('id');
        $this->post(route('help.links.store', 'searching'), ['target' => $target])->assertRedirect();
        $this->get(route('help.article', 'fixity'))->assertSee('Searching');
        $this->delete(route('help.links.destroy', ['searching', $target]))->assertRedirect();
        $this->assertSame(0, DB::table('help_article_links')->where('manual', true)->count());
        auth()->logout();
        $this->post(route('help.links.store', 'searching'), ['target' => $target])->assertRedirect(route('sign-in'));
    }

    public function test_the_system_map_and_breakdown_link_only_to_guides_that_exist(): void
    {
        $this->get(route('help.system-map'))->assertOk()->assertSee('Intake')->assertSee('Preservation')->assertDontSee(route('help.article', 'accession-manage-user-guide'), false);
        $this->get(route('help.system-breakdown'))->assertOk()->assertSee('OpenRiC Workspace');
    }

    public function test_the_guides_that_ship_all_read_in_and_their_links_resolve(): void
    {
        config(['workspace.help_directory' => '']);
        $counts = app(HelpLibrary::class)->ingest();
        $files = glob(base_path('docs/help/*.md'));
        $this->assertSame(count($files), $counts['articles']);
        $this->assertGreaterThanOrEqual(60, $counts['articles']);
        $slugs = DB::table('help_articles')->pluck('slug')->flip();
        foreach (DB::table('help_articles')->get() as $article) {
            $this->assertContains($article->category, HelpLibrary::CATEGORIES, $article->slug.': a known category');
            $this->assertNotSame($article->slug, $article->title, $article->slug.': a title');
            preg_match_all('~/help/article/([a-z0-9-]+)~', $article->body_markdown, $m);
            foreach ($m[1] as $target) {
                $this->assertTrue(isset($slugs[$target]), "{$article->slug} links to $target, which is not a guide");
            }
            $this->assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}\x{2015}]/u', $article->body_markdown, $article->slug.': plain hyphens only');
        }
        $walk = function (array $nodes) use (&$walk, $slugs) {
            foreach ($nodes as $node) {
                ! isset($node['help']) || $this->assertTrue(isset($slugs[$node['help']]), 'the map names '.$node['help'].', which is not a guide');
                $walk($node['children'] ?? []);
            }
        };
        $walk(require resource_path('help/system-map.php'));
        $walk(require resource_path('help/system-breakdown.php'));
        $this->assertTrue(app(HelpLibrary::class)->forRoute('records.show', true)?->slug === 'information-object-manage-user-guide');
    }

    private function guide(string $slug, string $header, string $body): void
    {
        file_put_contents($this->dir.'/'.$slug.'.md', "---\n$header\n---\n\n$body\n");
    }
}
