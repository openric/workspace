<?php

// OpenRiC Workspace - heritage at risk and displaced heritage (roadmap phase 17d3), after Heratio's ahg-semantic-search:
// a description traced as displaced only when its provenance places its origin and its repository or provenance its
// holding in different regions, and a claim prefilled from the trace; a description flagged for capture by who may
// change it, one flag a description, the worklist most urgent first and a captured one off it; the public register,
// its figures and the network board only where the institution shows them and only of what is published; the register
// answering peers signed where shared, a peer's register read into the board, and a peer's push kept for review until
// accepted.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Audit\Crypto\KeyPair;
use App\Description\ContactPointService;
use App\Description\ProvenanceService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Heritage\Displaced;
use App\Heritage\Endangered;
use App\Heritage\EndangeredNetwork;
use App\Models\Concept;
use App\Models\EndangeredInboundFlag;
use App\Models\EndangeredItem;
use App\Models\EndangeredSetting;
use App\Models\FederationPeer;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\RepatriationSetting;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HeritageAtRiskTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private Institution $institution;

    private User $admin;

    private User $editor;

    private RecordResource $mask;

    private RecordResource $reel;

    private RecordResource $draft;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        config(['workspace.webhooks_check_hosts' => false]);
        $this->institution = Institution::create(['name' => 'Riverside Museum', 'base_iri' => 'https://riverside.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->admin = app(AccountService::class)->create($this->institution, 'Admin', 'admin@riverside.example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->admin, 'administrator');
        $this->editor = app(AccountService::class)->create($this->institution, 'Editor', 'editor@riverside.example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->editor, 'editor');
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Museum', 'en', 'RM');
        app(ContactPointService::class)->save($holder, ['country_code' => 'GB', 'city' => 'London', 'is_primary' => true], null, $this->admin);
        $this->mask = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Carved mask', 'en', null, $holder, 'RM1');
        $this->reel = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Nitrate film reel', 'en', null, $holder, 'RM2');
        $this->draft = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Brass plaque', 'en', null, $holder, 'RM3');
        foreach ([$holder, $this->mask, $this->reel] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->admin);
        }
    }

    public function test_displaced_heritage_is_traced_only_where_origin_and_holding_differ(): void
    {
        $provenance = app(ProvenanceService::class);
        $provenance->saveEntry($this->mask, null, ['owner_name' => 'Court of the Oba', 'owner_location' => 'Benin City, Nigeria'], $this->admin);
        $provenance->saveEntry($this->mask, null, ['owner_name' => 'A London dealer', 'owner_location' => 'London'], $this->admin);
        $provenance->saveEntry($this->reel, null, ['owner_name' => 'A studio', 'owner_location' => 'Somewhere unrecorded'], $this->admin);
        $provenance->saveEntry($this->draft, null, ['owner_name' => 'A foundry', 'owner_location' => 'Birmingham, England'], $this->admin);

        $report = app(Displaced::class)->scan($this->institution, null, 0);
        $this->assertSame([3, 2, 1], [$report['scanned'], $report['evaluated'], $report['flagged_count']], 'an unknown origin is never flagged, the same region is not displaced');
        $this->assertSame(['Carved mask', 'Nigeria', 'United Kingdom', 'repository_country_code'],
            [$report['records'][0]['title'], $report['records'][0]['origin_region'], $report['records'][0]['holding_region'], $report['records'][0]['holding']['field']]);
        $this->assertSame('Sudan', app(Displaced::class)->regionFor('Nubian'), 'a people names its region');
        $this->assertNull(app(Displaced::class)->regionFor('Somaliland'), 'whole words only: not Mali');

        $this->actingAs($this->editor)->get(route('heritage.displaced'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('heritage.displaced'))->assertOk()->assertSee('Carved mask')->assertDontSee('Nitrate film reel');
        $this->actingAs($this->admin)->get(route('repatriation.create', ['record' => $this->mask->id]))->assertSee('value="Benin City, Nigeria"', false);

        auth()->logout();
        $this->get(route('discover.displaced'))->assertNotFound();
        RepatriationSetting::create(['institution_id' => $this->institution->id, 'public_register' => true]);
        $this->get(route('discover.displaced'))->assertOk()->assertSee('Carved mask');
        $this->get(route('discover.displaced.show', $this->mask->id))->assertOk()->assertSee('Nigeria');
        $this->get(route('discover.displaced.show', $this->reel->id))->assertNotFound();
    }

    public function test_a_flag_puts_a_description_on_the_worklist_until_captured(): void
    {
        $this->actingAs($this->editor)->post(route('heritage.flag.store'), ['record' => $this->mask->id, 'risk' => 'decay', 'urgency' => 'medium', 'capture_status' => 'flagged'])->assertRedirect();
        $this->actingAs($this->editor)->post(route('heritage.flag.store'), ['record' => $this->reel->id, 'risk' => 'digitisation-gap', 'urgency' => 'critical', 'capture_status' => 'flagged',
            'reason' => 'Nitrate base is decomposing.'])->assertRedirect();
        $this->actingAs($this->editor)->post(route('heritage.flag.store'), ['record' => $this->mask->id, 'risk' => 'decay', 'urgency' => 'high', 'capture_status' => 'in-progress'])->assertRedirect();
        $this->assertSame(2, EndangeredItem::count(), 'one flag a description, the second changing it');

        $worklist = app(Endangered::class)->priorityList($this->institution);
        $this->assertSame([$this->reel->id, $this->mask->id], $worklist->pluck('record_resource_id')->all());
        $this->assertSame([1005, 100], $worklist->map(fn ($i) => Endangered::score($i->urgency->notation, $i->risk->notation, $i->captureStatus->notation))->all());
        $this->actingAs($this->editor)->get(route('heritage.at-risk'))->assertOk()->assertSee('Nitrate film reel')->assertDontSee('Let federation peers push');
        $this->actingAs($this->editor)->get(route('records.show', $this->reel))->assertSee('Nitrate base is decomposing.');

        $this->actingAs($this->editor)->post(route('heritage.capture-status', EndangeredItem::where('record_resource_id', $this->mask->id)->sole()), ['capture_status' => 'captured'])->assertRedirect();
        $this->assertSame([$this->reel->id], app(Endangered::class)->priorityList($this->institution)->pluck('record_resource_id')->all());
        $this->actingAs($this->editor)->post(route('heritage.flag.store'), ['record' => $this->reel->id, 'risk' => 'meteor', 'urgency' => 'high', 'capture_status' => 'flagged'])->assertSessionHasErrors('flag');

        auth()->logout();
        $this->get(route('discover.at-risk'))->assertNotFound();
        EndangeredSetting::create(['institution_id' => $this->institution->id, 'public_register' => true]);
        $this->actingAs($this->editor)->post(route('heritage.flag.store'), ['record' => $this->draft->id, 'risk' => 'climate', 'urgency' => 'critical', 'capture_status' => 'flagged']);
        auth()->logout();
        $this->get(route('discover.at-risk'))->assertOk()->assertSee('Nitrate film reel')->assertDontSee('Brass plaque')->assertSee('33%');
        $this->getJson(route('discover.at-risk.json'))->assertOk()->assertJsonPath('total', 3)->assertJsonPath('public_total', 1)->assertJsonPath('priority.0.risk_category', 'digitisation_gap');
    }

    public function test_peers_read_the_register_signed_and_push_flags_for_review(): void
    {
        app(Endangered::class)->flag($this->reel, ['risk' => 'digitisation-gap', 'urgency' => 'critical'], $this->editor);
        $this->get('https://riverside.example.org/federation/endangered')->assertStatus(403);
        EndangeredSetting::create(['institution_id' => $this->institution->id, 'share_with_peers' => true]);
        $answer = $this->get('https://riverside.example.org/api/v1/endangered')->assertOk()->assertHeader('X-Federation-Sig-Alg', 'ed25519-sha256-hex');
        $this->assertSame([$this->reel->iri, 'digitisation_gap', 'critical', 1005], [$answer->json('items.0.item_ref'), $answer->json('items.0.risk_category'), $answer->json('items.0.urgency'), $answer->json('items.0.priority_score')]);

        $type = fn ($n) => Concept::where('iri', Vocab::BASE.'federation-peer-type/'.$n)->value('id');
        $peer = FederationPeer::create(['institution_id' => $this->institution->id, 'name' => 'Far Museum', 'type_id' => $type('heratio'), 'base_url' => 'https://far.example.net',
            'is_active' => true, 'search_enabled' => true]);
        $key = KeyPair::generate();
        Http::fake(function ($request) use ($key) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/api/v1/endangered') {
                $body = json_encode(['institution' => 'Far Museum', 'items' => [['item_ref' => 'far-scroll', 'title' => 'Palm-leaf scroll', 'risk_category' => 'climate', 'urgency' => 'high',
                    'capture_status' => 'flagged', 'catalogue_url' => 'https://far.example.net/far-scroll']]]);

                return Http::response($body, 200, ['Content-Type' => 'application/json', 'X-Federation-Signature' => bin2hex(sodium_crypto_sign_detached(hash('sha256', $body, true), $key->secretKey())),
                    'X-Federation-Key-Id' => $key->kid(), 'X-Federation-Sig-Alg' => 'ed25519-sha256-hex']);
            }
            if ($path === '/open-data/protocol') {
                return Http::response(['federation' => ['public_key_url' => 'https://far.example.net/.well-known/openric-federation-key']]);
            }

            return Http::response(['keys' => [['kid' => $key->kid(), 'public_key' => ['hex' => $key->publicKeyHex()]]]]);
        });
        $board = app(EndangeredNetwork::class)->board($this->institution);
        $this->assertSame([$this->reel->iri, 'far-scroll'], array_column($board['items'], 'item_ref'), 'ranked by score: critical before high');
        $this->assertContains($board['items'][1]['source_peer']['verification'], ['verified', 'pinned']);

        $push = fn (string $from, array $item) => $this->call('POST', 'https://riverside.example.org/api/v1/endangered/inbound', [], [], [],
            ['HTTP_X_FEDERATION_PEER' => $from, 'CONTENT_TYPE' => 'application/json'], json_encode(['item' => $item]));
        $item = ['item_ref' => 'far-drum', 'title' => 'Ceremonial drum', 'risk_category' => 'conflict', 'urgency' => 'critical', 'reason' => 'The storeroom is in a conflict zone.'];
        $push('https://far.example.net', $item)->assertStatus(403);
        EndangeredSetting::for($this->institution->id)->fill(['accept_pushes' => true])->save();
        $push('https://unknown.example.net', $item)->assertStatus(403);
        $push('https://far.example.net', $item)->assertOk()->assertJson(['accepted' => true, 'review_status' => 'pending', 'verified' => false]);
        $push('https://far.example.net', [...$item, 'urgency' => 'high'])->assertOk();
        $flag = EndangeredInboundFlag::sole();
        $this->assertSame(['high', $peer->id], [$flag->urgency->notation, $flag->peer_id], 'a repeated push updates the one flag');
        $this->assertNotContains('far-drum', array_column(app(EndangeredNetwork::class)->board($this->institution)['items'], 'item_ref'), 'not shown until accepted');

        $this->actingAs($this->editor)->get(route('heritage.inbound'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('heritage.inbound'))->assertOk()->assertSee('Ceremonial drum');
        $this->actingAs($this->admin)->post(route('heritage.review', $flag), ['decision' => 'accepted'])->assertRedirect();
        $this->assertContains('far-drum', array_column(app(EndangeredNetwork::class)->board($this->institution)['items'], 'item_ref'));

        EndangeredSetting::for($this->institution->id)->fill(['public_register' => true])->save();
        auth()->logout();
        $this->get(route('discover.at-risk.network'))->assertOk()->assertSee('Palm-leaf scroll')->assertSee('Ceremonial drum')->assertSee('Nitrate film reel');
    }
}
