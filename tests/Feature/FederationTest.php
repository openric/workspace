<?php

// OpenRiC Workspace - federation (roadmap phase 16a): two institutions on one installation search each other's
// published catalogue and neither sees the other's drafts, nor anything from one that does not share; a catalogue
// answers a peer with a signed answer its published key verifies; a remote Workspace's signed answer is verified, its
// key pinned on first use, cached, and refused when it later signs with another key; and a peer at a private address is
// refused when it is added.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Audit\Crypto\KeyPair;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Federation\FederatedSearch;
use App\Models\Concept;
use App\Models\FederationPeer;
use App\Models\FederationShareSetting;
use App\Models\Institution;
use App\Models\User;
use App\Search\SearchIndex;
use App\Support\Vocab;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FederationTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    /** @return array{0: Institution, 1: User} */
    private function institution(string $name, string $host, string $email): array
    {
        $institution = Institution::create(['name' => $name, 'base_iri' => "https://$host"]);
        app(RoleService::class)->seedDefaults($institution);
        $admin = app(AccountService::class)->create($institution, 'Admin of '.$name, $email, 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($admin, 'administrator');

        return [$institution, $admin];
    }

    private function type(string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.'federation-peer-type/'.$notation)->value('id');
    }

    public function test_two_institutions_search_each_others_published_catalogue_and_never_a_draft(): void
    {
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        [$riverside, $riversideAdmin] = $this->institution('Riverside Archives', 'riverside.example.org', 'admin@riverside.example.org');
        [$hillside, $hillsideAdmin] = $this->institution('Hillside Archives', 'hillside.example.org', 'admin@hillside.example.org');
        $holder = app(RepositoryService::class)->create($hillside, 'Hillside Archives', 'en', 'HA');
        $published = app(RecordService::class)->create($hillside, self::LEVEL.'item', 'Minute book of the bridge committee', 'en', null, $holder, 'MB1');
        app(RecordService::class)->create($hillside, self::LEVEL.'item', 'Minute book draft of the bridge committee', 'en', null, $holder, 'MB2');
        foreach ([$holder, $published] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $hillsideAdmin);
        }
        app(SearchIndex::class)->flush();

        // Riverside adds Hillside, here, as its peer.
        $this->actingAs($riversideAdmin)->post(route('federation.peers.store'), ['name' => 'Hillside', 'type' => 'institution', 'peer_institution_id' => $hillside->id,
            'is_active' => 1, 'search_enabled' => 1])->assertSessionHasNoErrors();
        $this->actingAs($riversideAdmin)->post(route('federation.peers.store'), ['name' => 'Itself', 'type' => 'institution', 'peer_institution_id' => $riverside->id,
            'is_active' => 1, 'search_enabled' => 1])->assertSessionHasErrors('federation');

        // Hillside does not share yet: nothing comes back.
        $found = app(FederatedSearch::class)->search($riverside, 'minute book');
        $this->assertSame([[], 'error'], [$found['results'], $found['peers'][0]['status']]);
        $this->get('https://hillside.example.org/federation/search?q=minute')->assertStatus(403);

        // Once it shares, its published unit, and never its draft.
        FederationShareSetting::for($hillside->id)->fill(['share_enabled' => true])->save();
        $found = app(FederatedSearch::class)->search($riverside, 'minute book');
        $this->assertSame(['Minute book of the bridge committee'], array_column($found['results'], 'title'));
        $this->assertSame([$published->iri, 'Hillside', 'local'], [$found['results'][0]['id'], $found['results'][0]['peer'], $found['results'][0]['verification']]);
        $this->get('https://riverside.example.org/discover/federated?q=minute+book')->assertOk()->assertSee('Minute book of the bridge committee')->assertDontSee('draft of the bridge');
        $this->assertSame(3, \DB::table('federation_search_log')->where('institution_id', $riverside->id)->count());

        // A level not shared is not given.
        FederationShareSetting::for($hillside->id)->fill(['levels' => [Concept::where('iri', self::LEVEL.'fonds')->value('id')]])->save();
        $this->assertSame([], app(FederatedSearch::class)->answer($hillside, 'minute book')['results']);
        FederationShareSetting::for($hillside->id)->fill(['levels' => null])->save();

        // Hillside answers any peer, signed with its key: the key it publishes verifies the exact bytes.
        $answer = $this->get('https://hillside.example.org/federation/search?q=minute+book&limit=5')->assertOk()->assertHeader('X-Federation-Sig-Alg', 'ed25519-sha256-hex');
        $this->assertSame(['Minute book of the bridge committee'], array_column($answer->json('results'), 'title'));
        $key = $this->get('https://hillside.example.org/.well-known/openric-federation-key')->assertOk()->json('keys.0');
        $this->assertSame($key['kid'], $answer->headers->get('X-Federation-Key-Id'));
        $this->assertTrue(sodium_crypto_sign_verify_detached(hex2bin($answer->headers->get('X-Federation-Signature')), hash('sha256', $answer->getContent(), true), hex2bin($key['public_key']['hex'])));
        $this->assertSame(route('federation.key'), $this->get('https://hillside.example.org/open-data/protocol')->assertOk()->json('federation.public_key_url'));
        $this->get('https://riverside.example.org/open-data/federation')->assertOk()->assertJsonPath('peers.0.name', 'Hillside');

        $this->actingAs($riversideAdmin)->get(route('federation.admin', ['q' => 'minute']))->assertOk()->assertSee('Hillside')->assertSee('Minute book of the bridge committee');
    }

    public function test_a_remote_peers_signed_answer_is_verified_pinned_and_a_changed_key_refused(): void
    {
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        [$riverside, $admin] = $this->institution('Riverside Archives', 'riverside.example.org', 'admin@riverside.example.org');
        config(['workspace.webhooks_check_hosts' => false]);
        $peer = FederationPeer::create(['institution_id' => $riverside->id, 'name' => 'Far Archive', 'type_id' => $this->type('workspace'), 'base_url' => 'https://far.example.net',
            'api_key' => 'peer-key', 'is_active' => true, 'search_enabled' => true]);
        $key = KeyPair::generate();
        $other = KeyPair::generate();
        $signing = $key;
        $requests = 0;
        Http::fake(function ($request) use (&$signing, $key, &$requests) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/federation/search') {
                $requests++;
                $body = json_encode(['total' => 1, 'results' => [['id' => 'https://far.example.net/record/1', 'title' => 'Far letter', 'url' => 'https://far.example.net/record/1', 'score' => 0.9]]]);

                return Http::response($body, 200, ['Content-Type' => 'application/json', 'X-Federation-Signature' => bin2hex(sodium_crypto_sign_detached(hash('sha256', $body, true), $signing->secretKey())),
                    'X-Federation-Key-Id' => $signing->kid(), 'X-Federation-Sig-Alg' => 'ed25519-sha256-hex']);
            }
            if ($path === '/open-data/protocol') {
                return Http::response(['federation' => ['public_key_url' => 'https://far.example.net/.well-known/openric-federation-key']]);
            }
            if ($path === '/.well-known/openric-federation-key') {
                return Http::response(['keys' => [['kid' => $key->kid(), 'public_key' => ['hex' => $key->publicKeyHex()]]]]);
            }

            return Http::response('', 404);
        });

        $found = app(FederatedSearch::class)->search($riverside, 'letter', $admin);
        $this->assertSame(['Far letter', 'pinned'], [$found['results'][0]['title'], $found['results'][0]['verification']]);
        $this->assertSame($key->kid(), $peer->fresh()->pinned_key_id);
        Http::assertSent(fn ($r) => $r->hasHeader('X-API-Key', 'peer-key') && str_contains($r->url(), 'q=letter'));

        // The same search within fifteen minutes is answered from the cache.
        app(FederatedSearch::class)->search($riverside, 'letter', $admin);
        $this->assertSame(1, $requests);

        // Another key later: not trusted until the pin is cleared.
        $signing = $other;
        $found = app(FederatedSearch::class)->search($riverside, 'another letter', $admin);
        $this->assertSame([[], 'error', 'mismatch'], [$found['results'], $found['peers'][0]['status'], $found['peers'][0]['verification']]);
        $this->actingAs($admin)->post(route('federation.peers.clear-pin', $peer))->assertRedirect();
        $this->assertNull($peer->fresh()->pinned_key_id);

        // A peer at a private address is refused when it is added.
        config(['workspace.webhooks_check_hosts' => true]);
        $this->actingAs($admin)->post(route('federation.peers.store'), ['name' => 'Inside', 'type' => 'workspace', 'base_url' => 'http://127.0.0.1:8080', 'is_active' => 1])
            ->assertSessionHasErrors('federation');
        $this->assertSame(1, FederationPeer::count());
    }
}
