<?php

// OpenRiC Workspace - federation governance, discovery, vocabulary sync and joining (roadmap phase 16c): an untrusted
// peer is not asked, a peer asked too recently waits, and an unsigned answer is dropped where verification is required;
// a peer's protocol descriptor is probed; a peer's vocabulary is taken into the institution's own, each concept keeping
// the peer's IRI as an exact match, a concept the institution has kept or replaced as its rule says, and the
// institution offers its shared vocabulary with its changes; and a request to join, once approved, is a peer in the
// network directory.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Federation\Discovery;
use App\Federation\FederatedSearch;
use App\Federation\VocabularySync;
use App\Models\Concept;
use App\Models\ConceptMatch;
use App\Models\FederationPeer;
use App\Models\FederationShareSetting;
use App\Models\Institution;
use App\Models\User;
use App\Support\Vocab;
use App\Vocabulary\ConceptService;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FederationGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://riverside.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->admin = app(AccountService::class)->create($this->institution, 'Admin', 'admin@riverside.example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->admin, 'administrator');
        config(['workspace.webhooks_check_hosts' => false]);
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE."$vocabulary/$notation")->value('id');
    }

    private function peer(string $name, string $host, array $fields = []): FederationPeer
    {
        return FederationPeer::create(['institution_id' => $this->institution->id, 'name' => $name, 'type_id' => $this->concept('federation-peer-type', 'workspace'),
            'base_url' => "https://$host", 'is_active' => true, 'search_enabled' => true, ...$fields]);
    }

    public function test_governance_decides_whom_to_ask_and_which_answers_to_keep(): void
    {
        $this->peer('Untrusted', 'untrusted.example.net', ['trust_level_id' => $this->concept('federation-trust-level', 'untrusted')]);
        $this->peer('Unsigned', 'unsigned.example.net', ['trust_level_id' => $this->concept('federation-trust-level', 'basic'), 'rate_limit_seconds' => 3600]);
        Http::fake(['https://unsigned.example.net/*' => Http::response(['results' => [['id' => 'https://unsigned.example.net/r/1', 'title' => 'Unsigned letter']]])]);

        $found = app(FederatedSearch::class)->search($this->institution, 'letter');
        $this->assertSame(['Unsigned'], array_map(fn ($p) => $p['peer']->name, $found['peers']), 'the untrusted peer is not asked');
        $this->assertSame(['Unsigned letter', 'unsigned'], [$found['results'][0]['title'], $found['results'][0]['verification']]);

        // Asked too recently for a new search: it waits for its rate limit.
        $again = app(FederatedSearch::class)->search($this->institution, 'another');
        $this->assertSame('skipped', $again['peers'][0]['status']);

        // Where the institution requires it, an unsigned answer is dropped.
        FederationShareSetting::for($this->institution->id)->fill(['require_verified' => true])->save();
        FederationPeer::where('name', 'Unsigned')->update(['rate_limit_seconds' => null]);
        $this->assertSame([], app(FederatedSearch::class)->search($this->institution, 'letter')['results']);
    }

    public function test_a_peers_protocol_is_probed(): void
    {
        $ok = $this->peer('Far', 'far.example.net');
        $plain = $this->peer('Plain', 'plain.example.net');
        $gone = $this->peer('Gone', 'gone.example.net');
        Http::fake([
            'https://far.example.net/open-data/protocol' => Http::response(['protocol_version' => '1.0', 'federation' => ['surfaces' => ['search', 'union'], 'search_url' => 'https://far.example.net/federation/search']]),
            'https://far.example.net/open-data/maturity' => Http::response(['grade' => 'B']),
            'https://plain.example.net/*' => Http::response(['hello' => 'world']),
            'https://gone.example.net/*' => Http::response('', 503),
        ]);
        $this->assertSame(['Far' => 'ok', 'Plain' => 'non-compliant', 'Gone' => 'unreachable'], app(Discovery::class)->probeAll($this->institution->id));
        $this->assertSame(['1.0', ['search', 'union'], 'B'], [$ok->fresh()->protocol_version, $ok->fresh()->declared_surfaces, $ok->fresh()->maturity_grade]);
        $this->assertSame('non-compliant', $plain->fresh()->discoveryStatus->notation);
        $this->assertNotNull($gone->fresh()->last_probed_at);
    }

    public function test_a_peers_vocabulary_is_taken_in_with_exact_matches_and_ours_is_offered_with_its_changes(): void
    {
        $peer = $this->peer('Far', 'far.example.net');
        $subjects = app(ConceptService::class)->createScheme($this->institution, 'subject', 'Subjects', 'en', null, $this->admin);
        $turtle = fn (string $bridgeLabel) => '@prefix skos: <http://www.w3.org/2004/02/skos/core#> .
<https://far.example.net/vocabulary/s> a skos:ConceptScheme .
<https://far.example.net/vocabulary/s/bridges> a skos:Concept ; skos:inScheme <https://far.example.net/vocabulary/s> ; skos:prefLabel "'.$bridgeLabel.'"@en .
<https://far.example.net/vocabulary/s/tolls> a skos:Concept ; skos:inScheme <https://far.example.net/vocabulary/s> ; skos:prefLabel "Tolls"@en .';
        $label = 'Bridges';
        Http::fake(function () use (&$label, $turtle) {
            return Http::response($turtle($label), 200, ['Content-Type' => 'text/turtle']);
        });
        $this->actingAs($this->admin)->post(route('federation.syncs.store'), ['peer_id' => $peer->id, 'vocabulary_id' => $subjects->id, 'remote_vocabulary' => 'subjects-uuid',
            'direction' => 'pull', 'conflict_rule' => 'prefer-remote', 'sync_enabled' => 1])->assertSessionHasNoErrors();
        $sync = DB::table('federation_vocabulary_syncs')->sole();

        $log = app(VocabularySync::class)->run($sync, $this->admin);
        $this->assertSame([2, 0, null], [$log->terms_added, $log->conflicts, $log->error_message]);
        $bridges = Concept::find(ConceptMatch::where('target_iri', 'https://far.example.net/vocabulary/s/bridges')->sole()->concept_id);
        $this->assertSame([$subjects->id, true], [$bridges->vocabulary_id, str_starts_with($bridges->iri, $this->institution->base_iri)], 'our IRI, the peer\'s as an exact match');
        Http::assertSent(fn ($r) => $r->url() === 'https://far.example.net/federation/vocabularies/subjects-uuid.ttl');

        // Take theirs: the concept we have takes the peer's label.
        $label = 'Bridges and causeways';
        $log = app(VocabularySync::class)->run($sync, $this->admin);
        $this->assertSame([0, 2, 2], [$log->terms_added, $log->terms_updated, $log->conflicts]);
        $this->assertSame('Bridges and causeways', $bridges->fresh()->label(['en']));

        // Keep ours: nothing we have changes.
        DB::table('federation_vocabulary_syncs')->where('id', $sync->id)->update(['conflict_rule_id' => $this->concept('vocabulary-conflict-rule', 'prefer-local')]);
        $label = 'Something else';
        $log = app(VocabularySync::class)->run(DB::table('federation_vocabulary_syncs')->find($sync->id), $this->admin);
        $this->assertSame([0, 0, 2], [$log->terms_added, $log->terms_updated, $log->conflicts]);
        $this->assertSame('Bridges and causeways', $bridges->fresh()->label(['en']));

        // We offer our vocabulary, once shared, with the changes made to it since.
        $this->getJson('https://riverside.example.org/federation/vocabularies')->assertOk()->assertJsonCount(0, 'vocabularies');
        FederationShareSetting::for($this->institution->id)->fill(['share_enabled' => true, 'shared_vocabularies' => [$subjects->id]])->save();
        $since = now()->subSecond()->toIso8601String();
        app(ConceptService::class)->create($subjects, ['language' => 'en', 'label' => 'Ferries'], $this->admin);
        $listed = $this->getJson('https://riverside.example.org/federation/vocabularies')->assertOk()->json('vocabularies.0');
        $this->assertSame([$subjects->id, route('federation.vocabulary', $subjects->id)], [$listed['id'], $listed['turtle']]);
        $this->get('https://riverside.example.org/federation/vocabularies/'.$subjects->id.'.ttl')->assertOk()->assertHeader('Content-Type', 'text/turtle; charset=utf-8')->assertSee('Ferries');
        $this->assertSame(['created'], array_column($this->getJson('https://riverside.example.org/federation/vocabularies/'.$subjects->id.'/changes?since='.urlencode($since))->json('changes'), 'change'));
        $this->get('https://riverside.example.org/open-data/protocol')->assertJsonPath('federation.vocabularies_url', route('federation.vocabularies'));
    }

    public function test_a_request_to_join_once_approved_is_a_peer_in_the_network(): void
    {
        $this->get('https://riverside.example.org/federation/join')->assertOk()->assertSee('Ask to join');
        // A robot filling the hidden field is thanked, and nothing is kept.
        $this->post('https://riverside.example.org/federation/join', ['institution_name' => 'Spam', 'contact_name' => 'x', 'contact_email' => 'x@example.com', 'base_url' => 'https://spam.example.com',
            'website' => 'http://spam'])->assertRedirect();
        $this->post('https://riverside.example.org/federation/join', ['institution_name' => 'Hillside Archives', 'contact_name' => 'Thandi Mokoena', 'contact_email' => 'thandi@hillside.example.org',
            'base_url' => 'https://hillside.example.org', 'what_they_share' => 'Municipal records, 1880 to 1950'])->assertRedirect();
        $this->assertSame(['Hillside Archives'], DB::table('federation_join_requests')->pluck('institution_name')->all());
        $join = DB::table('federation_join_requests')->sole();

        $this->actingAs($this->admin)->get(route('federation.admin'))->assertOk()->assertSee('Hillside Archives')->assertSee('Approve');
        $this->actingAs($this->admin)->post(route('federation.joins.decide', [$join->id, 'approve']))->assertRedirect();
        $peer = FederationPeer::sole();
        $this->assertSame(['Hillside Archives', 'https://hillside.example.org', 'basic'], [$peer->name, $peer->base_url, $peer->trustLevel->notation]);
        $this->assertSame([$peer->id, 'approved'], [DB::table('federation_join_requests')->value('peer_id'), Concept::find(DB::table('federation_join_requests')->value('status_id'))->notation]);

        $network = $this->getJson('https://riverside.example.org/open-data/network')->assertOk()->json();
        $this->assertSame(['Hillside Archives', 'basic'], [$network['peers'][0]['name'], $network['peers'][0]['trust_level']]);
        $this->assertSame(route('federation.join'), $network['join']);
    }
}
