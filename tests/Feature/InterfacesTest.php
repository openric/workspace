<?php

// OpenRiC Workspace - the interfaces of roadmap phase 22c beside webhooks: sitemaps, robots.txt and the Atom feed list
// only what the public may read; METS carries the units and files the reader may receive; OpenAPI describes the routes
// the API has; GraphQL answers what the public may read and nothing else, and writes nothing; places within a box or a
// radius come as GeoJSON; and WebVTT caption tracks are checked, played with the recording and withheld with it.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\InstantiationService;
use App\Description\PlaceService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Media\Captions;
use App\Models\CaptionTrack;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Tests\TestCase;

class InterfacesTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private const VTT = "WEBVTT\n\n00:00:00.000 --> 00:00:01.000\nThe council meets.\n\nNOTE spoken by the clerk\n\n2\n00:00:01.000 --> 00:00:02.000\nThe minutes are read.\n";

    private string $store;

    private Institution $institution;

    private User $editor;

    private RecordResource $fonds;

    private RecordResource $letter;

    private RecordResource $closed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->editor = app(AccountService::class)->create($this->institution, 'Editor', 'editor@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->editor, 'editor');
        $records = app(RecordService::class);
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $this->fonds = $records->create($this->institution, self::LEVEL.'fonds', 'Riverside Parish Council records', 'en', null, $holder, 'RPC');
        $this->letter = $records->create($this->institution, self::LEVEL.'item', 'Letter of 3 May 1902', 'en', $this->fonds, null, '1');
        $records->describe($this->letter, ['scope-and-content' => 'Names the witness at the marriage.'], 'en');
        $this->closed = $records->create($this->institution, self::LEVEL.'item', 'Closed personnel file', 'en', $this->fonds, null, '2');
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

    public function test_sitemaps_robots_and_the_feed_list_only_what_the_public_may_read(): void
    {
        $this->get('/robots.txt')->assertOk()->assertSee('Sitemap: '.route('sitemap'), false);
        $this->get('/sitemap.xml')->assertOk()->assertSee(route('sitemap.kind', 'records'), false);
        $records = $this->get('/sitemap-records.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=utf-8')->getContent();
        $this->assertStringContainsString(route('discover.record', $this->letter->id), $records);
        $this->assertStringNotContainsString($this->closed->id, $records);
        $this->assertNotFalse(simplexml_load_string($records));

        $feed = $this->get('/feed.atom')->assertOk()->assertHeader('Content-Type', 'application/atom+xml; charset=utf-8')->getContent();
        $atom = simplexml_load_string($feed);
        $this->assertSame('Newly published at Riverside Archives', (string) $atom->title);
        $this->assertContains($this->letter->iri, array_map('strval', $atom->xpath('//*[local-name()="entry"]/*[local-name()="id"]')));
        $this->assertStringContainsString('Names the witness at the marriage.', $feed);
        $this->assertStringNotContainsString('Closed personnel file', $feed);
    }

    public function test_mets_carries_the_units_and_the_files_the_reader_may_receive(): void
    {
        app(InstantiationService::class)->upload($this->letter, $this->video(), 'meeting.mp4', 'en', 'Film of the meeting', null, 'online', $this->editor);
        $mets = $this->actingAs($this->editor)->get(route('records.export.download', [$this->fonds, 'mets']))->assertOk()->getContent();
        $xml = simplexml_load_string($mets);
        $xml->registerXPathNamespace('m', 'http://www.loc.gov/METS/');
        $this->assertSame($this->fonds->iri, (string) $xml['OBJID']);
        $this->assertCount(3, $xml->xpath('//m:dmdSec'), 'the fonds and both items, for an editor');
        $master = $xml->xpath('//m:fileGrp[@USE="master"]/m:file')[0];
        $this->assertSame('SHA-256', (string) $master['CHECKSUMTYPE']);
        $this->assertSame($this->letter->instantiations()->sole()->file('master')->sha256, (string) $master['CHECKSUM']);
        // The structMap nests the letter in the fonds and points at its files: master, reference copy and thumbnail.
        $this->assertCount(3, $xml->xpath('//m:structMap/m:div/m:div[@LABEL="Letter of 3 May 1902"]/m:fptr'));
    }

    public function test_openapi_describes_the_routes_the_api_has(): void
    {
        $doc = $this->getJson('/api/ric/v1/openapi.json')->assertOk()->json();
        $this->assertSame('3.1.0', $doc['openapi']);
        $this->assertSame('api.graphql', $doc['paths']['/graphql']['get']['operationId']);
        $this->assertSame('api.graphql.post', $doc['paths']['/graphql']['post']['operationId']);
        $this->assertSame([['apiKey' => []]], $doc['paths']['/records/{key}/proposals']['post']['security'], 'proposing needs a key');
        $this->assertArrayHasKey('/places/within', $doc['paths']);
        $this->assertSame(['key'], array_column($doc['paths']['/records/{key}']['get']['parameters'], 'name'));
    }

    public function test_graphql_answers_what_the_public_may_read_and_writes_nothing(): void
    {
        $answer = $this->postJson('/api/ric/v1/graphql', ['query' => 'query Units($q: String) { units: records(q: $q, limit: 10) { id title: name level } holder: repositories { name } }',
            'variables' => ['q' => 'letter']])->assertOk()->json();
        $this->assertSame([['id' => $this->letter->id, 'title' => 'Letter of 3 May 1902', 'level' => 'item']], $answer['data']['units']);
        $this->assertSame([['name' => 'Riverside Archives']], $answer['data']['holder']);

        $one = fn (string $id) => $this->getJson('/api/ric/v1/graphql?'.http_build_query(['query' => '{ record(id: "'.$id.'") { name parent api } }']))->assertOk()->json('data.record');
        $this->assertSame(['name' => 'Letter of 3 May 1902', 'parent' => $this->fonds->id, 'api' => route('api.record', $this->letter->id)], $one($this->letter->id));
        $this->assertNull($one($this->closed->id), 'not published, so not there');

        foreach (['mutation { deleteRecord(id: "x") { id } }' => 'read only', '{ records { secret } }' => 'Cannot query field "secret"',
            '{ records(limit: 1000) { id } }' => 'limit is from 1 to 100', '{ users { id } }' => 'Cannot query field "users"'] as $query => $error) {
            $this->assertStringContainsString($error, $this->postJson('/api/ric/v1/graphql', ['query' => $query])->assertOk()->json('errors.0.message'), $query);
        }
    }

    public function test_places_within_a_box_or_radius_come_as_geojson(): void
    {
        $places = app(PlaceService::class);
        $make = function (string $name, string $lat, string $lon, bool $publish = true) use ($places) {
            $place = $places->create($this->institution, $name, 'en');
            $places->updateFacts($place, null, $lat, $lon, null);
            $publish && app(PublicationService::class)->apply($place, 'publish', null, false, $this->editor);

            return $place;
        };
        $pretoria = $make('Pretoria', '-25.7461', '28.1881');
        $make('Johannesburg', '-26.2041', '28.0473');
        $make('Cape Town', '-33.9249', '18.4241');
        $make('Secret farm', '-25.75', '28.19', false);
        $places->create($this->institution, 'Nowhere in particular', 'en');

        $box = $this->getJson('/api/ric/v1/places/within?bbox=27.5,-26.5,28.5,-25.5')->assertOk()->assertHeader('Content-Type', 'application/geo+json');
        $this->assertSame('FeatureCollection', $box->json('type'));
        $this->assertSame(['Johannesburg', 'Pretoria'], collect($box->json('features'))->pluck('properties.name')->all());
        $this->assertSame(['type' => 'Point', 'coordinates' => [28.1881, -25.7461]], collect($box->json('features'))->firstWhere('id', $pretoria->id)['geometry']);
        // Pretoria to Johannesburg is about 53 km.
        $near = fn ($km) => collect($this->getJson("/api/ric/v1/places/within?lat=-25.7461&lon=28.1881&radius=$km")->assertOk()->json('features'))->pluck('properties.name')->all();
        $this->assertSame(['Pretoria'], $near(40));
        $this->assertSame(['Johannesburg', 'Pretoria'], $near(60));
        $this->getJson('/api/ric/v1/places/within?bbox=1,2,3')->assertStatus(400);
        $this->getJson('/api/ric/v1/places/within?lat=1&lon=2')->assertStatus(400);
    }

    public function test_caption_tracks_are_checked_played_with_the_recording_and_withheld_with_it(): void
    {
        foreach (['Hello' => 'starts with the line WEBVTT', "WEBVTT\n\nno timing here" => 'no timing line', "WEBVTT\n\n00:00:05.000 --> 00:00:01.000\nBackwards" => 'ends before it starts',
            "WEBVTT\n" => 'no cues'] as $text => $error) {
            try {
                Captions::cues($text);
                $this->fail("$error was accepted");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($error, $e->getMessage());
            }
        }
        $this->assertSame(2, Captions::cues(self::VTT));

        $film = app(InstantiationService::class)->upload($this->letter, $this->video(), 'meeting.mp4', 'en', 'Film of the meeting', null, 'online', $this->editor);
        app(PublicationService::class)->apply($film, 'publish', null, false, $this->editor);
        $upload = fn (string $text, string $language) => $this->actingAs($this->editor)->post(route('captions.store', $film),
            ['file' => UploadedFile::fake()->createWithContent('meeting.vtt', $text), 'language' => $language, 'kind' => 'subtitles', 'label' => strtoupper($language), 'is_default' => 1]);
        $upload('not captions', 'en')->assertSessionHasErrors('captions');
        $upload(self::VTT, 'en')->assertSessionHas('status', 'Caption track kept: 2 cues.');
        $upload(self::VTT, 'af')->assertRedirect();
        $this->assertSame(['af' => true, 'en' => false], CaptionTrack::pluck('is_default', 'language')->sortKeys()->all(), 'one default');

        // The public plays it with the recording, and the manifest offers it as a supplementing annotation.
        $track = CaptionTrack::where('language', 'en')->sole();
        auth()->logout();
        $this->get(route('discover.caption', $track))->assertOk()->assertHeader('Content-Type', 'text/vtt; charset=utf-8')->assertSee('The minutes are read.');
        $this->get(route('discover.record', $this->letter->id))->assertOk()->assertSee('srclang="af"', false)->assertSee(route('discover.caption', $track), false);
        $canvas = collect($this->get(route('iiif.manifest', $this->letter->id))->assertOk()->json('items'))->first(fn ($c) => isset($c['annotations']));
        $this->assertSame(['supplementing', 'text/vtt'], [$canvas['annotations'][0]['items'][0]['motivation'], $canvas['annotations'][0]['items'][0]['body']['format']]);

        // Withdrawn, the recording and its captions go together.
        app(PublicationService::class)->apply($film, 'withdraw', null, false, $this->editor);
        $this->get(route('discover.caption', $track))->assertNotFound();
        $this->actingAs($this->editor)->get(route('captions.show', $track))->assertOk();
        $this->actingAs($this->editor)->post(route('captions.destroy', $track))->assertRedirect(route('instantiations.show', $film));
        $this->assertSame(1, CaptionTrack::count());
    }

    /** Two seconds of video, 320 by 180, made by ffmpeg. */
    private function video(): string
    {
        $video = tempnam(sys_get_temp_dir(), 'video').'.mp4';
        exec('ffmpeg -v error -f lavfi -i testsrc=duration=2:size=320x180:rate=10 -pix_fmt yuv420p '.escapeshellarg($video), $out, $code);
        $this->assertSame(0, $code, 'ffmpeg made the test video');

        return $video;
    }
}
