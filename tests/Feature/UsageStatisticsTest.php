<?php

// OpenRiC Workspace - usage statistics of the public catalogue (roadmap phase 8): views and downloads counted, web crawlers apart, no address kept.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\InstantiationService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Institution;
use App\Relations\EntityKinds;
use App\Reports\UsageService;
use App\Support\Vocab;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Imagick;
use ImagickPixel;
use Tests\TestCase;

class UsageStatisticsTest extends TestCase
{
    use RefreshDatabase;

    private const BROWSER = 'Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0';

    private string $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        $this->loadBundledVocabularies();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->store)) {
            exec('rm -rf '.escapeshellarg($this->store));
        }
        parent::tearDown();
    }

    public function test_views_and_downloads_are_counted_by_day_with_crawlers_apart_and_no_address_kept(): void
    {
        $institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($institution);
        $editor = app(AccountService::class)->create($institution, 'Editor', 'editor@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($editor, 'editor');
        $holder = app(RepositoryService::class)->create($institution, 'Riverside Archives', 'en', 'RA');
        $letter = app(RecordService::class)->create($institution, Vocab::BASE.'level-of-description/item', 'Letter of 3 May 1902', 'en', null, $holder, 'L1');
        $image = new Imagick;
        $image->newImage(80, 60, new ImagickPixel('#d8c9a3'));
        $image->setImageFormat('png');
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($path, $image->getImageBlob());
        $scan = app(InstantiationService::class)->upload($letter, $path, 'letter.png', 'en', 'Scan of the letter');
        foreach ([$holder, $letter] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $editor);
        }
        $file = $scan->file('reference');

        // Two people view the letter, one of them twice; a search engine reads it; the picture in the page is not a download, asking for the file is.
        $this->withHeaders(['User-Agent' => self::BROWSER])->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->get("/discover/records/{$letter->id}")->assertOk();
        $this->withHeaders(['User-Agent' => self::BROWSER])->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->get("/discover/records/{$letter->id}")->assertOk();
        $this->withHeaders(['User-Agent' => self::BROWSER])->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get("/discover/records/{$letter->id}")->assertOk();
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'])->get("/discover/records/{$letter->id}")->assertOk();
        $this->withHeaders(['User-Agent' => self::BROWSER])->get("/discover/repositories/{$holder->id}")->assertOk();
        $this->withHeaders(['User-Agent' => self::BROWSER])->get("/discover/files/{$file->id}")->assertOk();
        $this->withHeaders(['User-Agent' => self::BROWSER])->get("/discover/files/{$file->id}?download=1")->assertOk();
        // The staff pages are not the public catalogue, and are not counted.
        $this->actingAs($editor)->get("/records/{$letter->id}")->assertOk();

        $this->assertSame(6, DB::table('usage_events')->count());
        $this->assertSame(1, DB::table('usage_events')->whereNotNull('crawler_id')->count());
        // No address or user agent is kept: the visitor is a hash under a salt of the day.
        $this->assertSame(0, DB::table('usage_events')->where('visitor', 'like', '%198.51%')->count());
        $this->assertFalse(collect(DB::getSchemaBuilder()->getColumnListing('usage_events'))->intersect(['ip', 'ip_address', 'user_agent', 'referer'])->isNotEmpty());

        $this->artisan('workspace:usage:aggregate')->assertSuccessful();
        $today = CarbonImmutable::today()->toDateString();
        $page = $this->actingAs($editor)->get('/reports/usage')->assertOk()->assertSee('Usage statistics')->assertSee('Googlebot');
        $statistics = app(UsageService::class)->statistics($institution->id, $today, $today);
        $this->assertSame([4, 1, 2, 1], [$statistics['views'], $statistics['downloads'], $statistics['visitors'], $statistics['crawlers']]);
        $this->assertSame('Letter of 3 May 1902', EntityKinds::label($statistics['viewed'][0]['entity']));
        $this->assertSame(3, $statistics['viewed'][0]['count']);
        $page->assertSeeInOrder(['Most viewed', 'Letter of 3 May 1902', '<td>3</td>', 'Most downloaded', 'Letter of 3 May 1902'], false);

        // One item, one repository; the CSV each day.
        $this->actingAs($editor)->get("/reports/usage?subject={$letter->id}")->assertOk()->assertSee('For Letter of 3 May 1902 only.');
        $this->actingAs($editor)->get("/reports/usage?repository={$holder->id}")->assertOk();
        $csv = $this->actingAs($editor)->get('/reports/usage?format=csv')->assertOk()->streamedContent();
        $this->assertStringStartsWith('Date,Views,Downloads', $csv);
        $this->assertStringContainsString("$today,4,1", $csv);
        $this->actingAs($editor)->get('/reports/usage?from=2026-10-10&to=2026-10-01')->assertSessionHasErrors('to');

        // Counting again replaces the day; events past the retention period go, the counts stay.
        $this->artisan('workspace:usage:aggregate')->assertSuccessful();
        $this->assertSame(4, app(UsageService::class)->statistics($institution->id, $today, $today)['views']);
        DB::table('usage_events')->update(['occurred_at' => now()->subDays(200)]);
        Cache::put('usage-salt-'.now()->subDay()->toDateString(), 'yesterday', now()->addDay());
        $this->assertSame(6, app(UsageService::class)->prune(90));
        $this->assertNull(Cache::get('usage-salt-'.now()->subDay()->toDateString()), 'a past day\'s salt is gone');
        $this->assertNotNull(Cache::get('usage-salt-'.now()->toDateString()), 'today\'s stays');
        $this->assertSame(4, app(UsageService::class)->statistics($institution->id, $today, $today)['views']);

        // The statistics are for staff: a visitor is sent to sign in.
        auth()->logout();
        $this->get('/reports/usage')->assertRedirect(route('sign-in'));
    }
}
