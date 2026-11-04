<?php

// OpenRiC Workspace - the institution's own pages on its public site (roadmap phase 20a): static pages in each language,
// linked from the foot of the catalogue, their HTML stripped; articles that the public sees only once published, with
// files served only then, related articles from both ends, comments with Heratio's guards and moderation, and reads
// counted once a session; and landing pages built of blocks, the default one the catalogue's home, whose figures and
// lists come only from what is published, with links made safe, and dashboards seen only by their owners.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\PlaceService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Files\FileStore;
use App\Models\Article;
use App\Models\ArticleComment;
use App\Models\Institution;
use App\Models\LandingPage;
use App\Models\LandingPageBlock;
use App\Models\StaticPage;
use App\Models\User;
use App\Site\Articles;
use App\Site\LandingPages;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Imagick;
use ImagickPixel;
use Tests\TestCase;

class PublicSiteTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private Institution $institution;

    private User $editor;

    private User $contributor;

    private string $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-20a-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://riverside.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $accounts = app(AccountService::class);
        $this->editor = $accounts->create($this->institution, 'Editor', 'editor@riverside.example.org', 'correct horse battery staple');
        $accounts->grantTemplateRole($this->editor, 'editor');
        $this->contributor = $accounts->create($this->institution, 'Contributor', 'contributor@riverside.example.org', 'correct horse battery staple');
        $accounts->grantTemplateRole($this->contributor, 'contributor');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->store));
        parent::tearDown();
    }

    public function test_static_pages_are_written_in_languages_and_shown_without_their_html(): void
    {
        $this->actingAs($this->contributor)->get(route('site.pages'))->assertForbidden();
        $this->actingAs($this->editor)->post(route('site.pages.store'), ['slug' => 'about', 'title' => 'About the archives', 'language' => 'en',
            'content' => "We keep the parish's records.\n\n<script>alert(1)</script>"])->assertRedirect();
        $page = StaticPage::sole();
        $this->put(route('site.pages.update', $page), ['slug' => 'about', 'title' => 'Oor die argief', 'language' => 'af', 'content' => 'Ons bewaar die gemeente se rekords.'])->assertRedirect();
        $this->post(route('site.pages.store'), ['slug' => 'about', 'title' => 'Again', 'language' => 'en'])->assertSessionHasErrors('slug');
        $this->post(route('site.pages.store'), ['slug' => 'Not A Slug', 'title' => 'Bad', 'language' => 'en'])->assertSessionHasErrors('slug');
        $this->assertEqualsCanonicalizing(['en', 'af'], $page->translations()->pluck('language')->all());
        auth()->logout();

        $this->get('/about')->assertOk()->assertSee('About the archives')->assertSee('parish', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->get('/pages/about')->assertOk();
        $this->get('/pages/missing')->assertNotFound();
        $this->get(route('discover.home'))->assertSee(route('site.about'), false)->assertDontSee(route('site.articles-public'), false);
    }

    public function test_articles_and_their_files_are_public_only_once_published(): void
    {
        $this->actingAs($this->editor)->post(route('site.articles.store'), ['title' => 'Conserving the 1902 letters', 'article_group' => 'Conservation', 'status' => 'draft',
            'body' => "The letters were **cleaned**.\n\n<iframe src=\"https://evil.example\"></iframe>", 'cover' => $this->png('cover.png')])->assertRedirect();
        $article = Article::sole();
        $this->assertSame('conserving-the-1902-letters', $article->slug);
        $this->post(route('site.attachments.store', $article), ['file' => UploadedFile::fake()->createWithContent('guide.pdf', "%PDF-1.4\n%guide\n"), 'kind' => 'guide', 'group_label' => 'Guides'])->assertSessionHasNoErrors();
        $this->post(route('site.attachments.store', $article), ['file' => UploadedFile::fake()->createWithContent('script.php', '<?php echo 1;'), 'kind' => 'guide'])->assertSessionHasErrors('file');
        $attachment = $article->attachments()->with('file')->sole();
        $this->assertSame('Guides', $attachment->group_label);
        $this->assertTrue(app(FileStore::class)->ocfl()->exists('https://riverside.example.org/site-file/'.$attachment->site_file_id), 'kept in OCFL');
        $this->get(route('site.file', $attachment->file))->assertOk();
        auth()->logout();

        $this->get(route('site.article', $article->slug))->assertNotFound();
        $this->get(route('site.file', $attachment->file))->assertNotFound();
        $this->get(route('site.articles-public'))->assertOk()->assertDontSee('Conserving the 1902 letters');

        $this->actingAs($this->editor)->put(route('site.articles.update', $article), ['title' => 'Conserving the 1902 letters', 'status' => 'published', 'article_group' => 'Conservation',
            'body' => $article->body])->assertRedirect();
        $this->assertNotNull($article->fresh()->published_at, 'stamped the first time it is published');
        $this->post(route('site.articles.store'), ['title' => 'Digitising the maps', 'status' => 'published', 'article_group' => 'Digitisation'])->assertRedirect();
        $second = Article::where('slug', 'digitising-the-maps')->sole();
        $this->post(route('site.links.store', $article), ['target' => $second->id, 'description' => 'The same project'])->assertRedirect();
        $this->post(route('site.articles.store'), ['title' => 'Not yet', 'status' => 'published', 'published_at' => now()->addWeek()->format('Y-m-d H:i')])->assertRedirect();
        auth()->logout();

        $page = $this->get(route('site.article', $article->slug))->assertOk()->assertSee('<strong>cleaned</strong>', false)->assertDontSee('<iframe', false)
            ->assertSee(route('site.file', $attachment->file), false)->assertSee('Digitising the maps');
        $this->get(route('site.article', $second->slug))->assertSee('Conserving the 1902 letters', false);
        $this->get(route('site.file', $attachment->file))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('site.file', $article->fresh()->cover))->assertOk();
        $this->get(route('site.articles-public'))->assertSee('Conserving the 1902 letters')->assertSee('Digitising the maps')->assertDontSee('Not yet');
        $this->get(route('site.articles-public', ['group' => 'Digitisation']))->assertDontSee('Conserving the 1902 letters')->assertSee('Digitising the maps');
        $this->get(route('site.article', 'not-yet'))->assertNotFound();
        $this->get(route('discover.home'))->assertSee(route('site.articles-public'), false);

        // Reads: by the page's script only, once a session.
        $this->post(route('site.article.read', $article->slug))->assertNotFound();
        $this->post(route('site.article.read', $article->slug), [], ['X-Requested-With' => 'XMLHttpRequest'])->assertNoContent();
        $this->post(route('site.article.read', $article->slug), [], ['X-Requested-With' => 'XMLHttpRequest'])->assertNoContent();
        $this->assertSame(1, $article->fresh()->view_count);

        // Deleting the article takes its files with it.
        $this->actingAs($this->editor)->delete(route('site.articles.destroy', $article))->assertRedirect();
        $this->assertFalse(app(FileStore::class)->ocfl()->exists('https://riverside.example.org/site-file/'.$attachment->site_file_id));
    }

    public function test_comments_are_guarded_and_moderated(): void
    {
        $article = app(Articles::class)->save($this->institution, ['title' => 'Open day', 'status' => 'published'], $this->editor);
        $this->post(route('site.article.comment', $article->slug), ['author_name' => 'Thandi', 'body' => 'Will the reading room be open?'])->assertRedirect();
        $this->post(route('site.article.comment', $article->slug), ['body' => 'Again, too soon.'])->assertSessionHas('comment_error');
        $this->post(route('site.article.comment', $article->slug), ['body' => 'Buy now', 'website' => 'https://spam.example'])->assertRedirect();
        $this->assertSame(1, ArticleComment::count(), 'the second too soon, the third by a robot');
        $this->get(route('site.article', $article->slug))->assertSee('Will the reading room be open?')->assertSee('Thandi');

        $comment = ArticleComment::sole();
        $this->actingAs($this->editor)->put(route('site.comments.update', $comment), ['status' => 'spam'])->assertRedirect();
        $this->get(route('site.comments'))->assertOk()->assertSee('Will the reading room be open?');
        auth()->logout();
        $this->get(route('site.article', $article->slug))->assertDontSee('Will the reading room be open?');
    }

    public function test_landing_pages_show_only_what_is_published(): void
    {
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $fonds = app(RecordService::class)->create($this->institution, self::LEVEL.'fonds', 'Riverside Parish Council records', 'en', null, $holder, 'RPC');
        app(RecordService::class)->create($this->institution, self::LEVEL.'fonds', 'Closed personnel files', 'en', null, $holder, 'CPF');
        $town = app(PlaceService::class)->create($this->institution, 'Riverside', 'en');
        $town->update(['latitude' => -25.7479, 'longitude' => 28.2293]);
        foreach ([$holder, $fonds, $town] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->editor);
        }

        $this->actingAs($this->contributor)->post(route('site.landing.store'), ['name' => 'Home'])->assertForbidden();
        $this->actingAs($this->editor)->post(route('site.landing.store'), ['name' => 'Home', 'is_default' => 1, 'starter' => 1])->assertRedirect();
        $page = LandingPage::sole();
        $this->assertSame(['hero-banner', 'search-box', 'statistics', 'browse-panels', 'recent-items'],
            LandingPageBlock::where('landing_page_id', $page->id)->orderBy('position')->with('type')->get()->map(fn ($b) => $b->type->notation)->all());
        $this->post(route('landing.blocks.store', $page), ['type' => 'row-2-col'])->assertRedirect();
        $row = LandingPageBlock::where('landing_page_id', $page->id)->orderByDesc('position')->first();
        $this->post(route('landing.blocks.store', $page), ['type' => 'holdings-list', 'parent' => $row->id, 'column' => 2])->assertRedirect();
        $this->post(route('landing.blocks.store', $page), ['type' => 'map', 'parent' => $row->id, 'column' => 1])->assertRedirect();
        $this->post(route('landing.blocks.store', $page), ['type' => 'row-1-col', 'parent' => $row->id])->assertSessionHasErrors('type');
        $hero = LandingPageBlock::where('landing_page_id', $page->id)->whereHas('type', fn ($q) => $q->where('notation', 'hero-banner'))->sole();
        $this->put(route('landing.blocks.update', $hero), ['is_visible' => 1, 'config' => ['title' => 'The Riverside archives', 'button_text' => 'Look', 'button_url' => 'javascript:alert(1)',
            'background_image' => 'https://images.example.org/river.jpg']])->assertRedirect();
        $this->assertSame('', $hero->fresh()->config['button_url'], 'no javascript: link');
        $this->get(route('landing.edit', $page))->assertOk()->assertSee(__('Column :n', ['n' => 2]))->assertSee(__('Holdings list'));
        auth()->logout();

        $home = $this->get(route('discover.home'))->assertOk()->assertSee('The Riverside archives')->assertSee('Riverside Parish Council records')
            ->assertDontSee('Closed personnel files')->assertDontSee('javascript:', false)->assertSee('vendor/leaflet/1.9.4/leaflet.min.js', false);
        $statistics = collect(app(LandingPages::class)->blocks($page, true))->first(fn ($b) => $b->type->notation === 'statistics')->data;
        $this->assertSame(1, collect($statistics)->firstWhere('kind', 'records')['count'], 'a count of what is published only');
        $this->get(route('discover.home', ['catalogue' => 1]))->assertOk()->assertSee(__('All collections'));
        $this->get(route('site.landing-page', 'home'))->assertOk()->assertSee('The Riverside archives');

        // Hidden blocks, and pages not shown, are not on the public site.
        $this->actingAs($this->editor)->post(route('landing.blocks.toggle', $hero))->assertRedirect();
        auth()->logout();
        $this->get(route('discover.home'))->assertDontSee('The Riverside archives');
        $this->actingAs($this->editor)->put(route('landing.update', $page), ['name' => 'Home', 'slug' => 'home'])->assertRedirect();
        auth()->logout();
        $this->get(route('site.landing-page', 'home'))->assertNotFound();
        $this->get(route('discover.home'))->assertSee(__('All collections'));
    }

    public function test_a_dashboard_is_its_owners_alone(): void
    {
        $this->actingAs($this->contributor)->post(route('dashboards.store'), ['name' => 'My desk', 'starter' => 1])->assertRedirect();
        $dashboard = LandingPage::sole();
        $this->assertSame($this->contributor->id, $dashboard->owner_id);
        $this->assertFalse($dashboard->is_default);
        $this->get(route('dashboards.show', $dashboard))->assertOk();
        $this->post(route('landing.blocks.store', $dashboard), ['type' => 'text-content'])->assertRedirect();
        $this->get(route('dashboards'))->assertSee('My desk');

        $this->actingAs($this->editor)->get(route('dashboards.show', $dashboard))->assertNotFound();
        $this->get(route('landing.edit', $dashboard))->assertNotFound();
        $this->get(route('site.landing-page', $dashboard->slug))->assertNotFound();
    }

    private function png(string $name): UploadedFile
    {
        $image = new Imagick;
        $image->newImage(40, 30, new ImagickPixel('#8fb3c9'));
        $image->setImageFormat('png');
        $path = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($path, $image->getImageBlob());

        return new UploadedFile($path, $name, 'image/png', null, true);
    }
}
