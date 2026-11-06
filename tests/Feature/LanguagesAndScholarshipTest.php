<?php

// OpenRiC Workspace - the languages of a collection and generative scholarship (roadmap phase 17d4), after Heratio's
// ahg-semantic-search: each language the published records are described in, Afrikaans just before Dutch, its records
// and terms, and a glossary and transcriptions shown once reviewed, a contributor named only with consent; a model
// shown only the published links around a record, its leads kept with those links, the confidence and the receipt,
// and the links kept where AI is off; research leads promoted from the discoveries and public only once published, on
// a published record; and the federation peers' holdings that share a record's access points.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\AgentService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Language\Corpus;
use App\Models\AiSetting;
use App\Models\FederationShareSetting;
use App\Models\Institution;
use App\Models\LanguageContribution;
use App\Models\LanguageGlossaryEntry;
use App\Models\RecordResource;
use App\Models\ResearchLead;
use App\Models\ScholarshipFederatedDiscovery;
use App\Models\User;
use App\Relations\RelationService;
use App\Scholarship\Scholarship;
use App\Search\SearchIndex;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LanguagesAndScholarshipTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private Institution $institution;

    private User $admin;

    private User $editor;

    private RecordResource $letter;

    private RecordResource $diary;

    private RecordResource $draft;

    /** @var list<string> what the model was asked */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        config(['workspace.ai.gateway_key' => 'test-key', 'workspace.ai.gateway_url' => 'https://ai.example.org/ai/v1']);
        [$this->institution, $this->admin] = $this->institution('Riverside Archives', 'riverside');
        $this->editor = app(AccountService::class)->create($this->institution, 'Editor', 'editor@riverside.example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->editor, 'editor');
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $fonds = app(RecordService::class)->create($this->institution, self::LEVEL.'fonds', 'Botha family papers', 'en', null, $holder, 'BF');
        $this->letter = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Brief oor die brug', 'af', $fonds, null, '1');
        $this->diary = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Dagboek van die boer', 'af', $fonds, null, '2');
        $dutch = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Brief van Amsterdam', 'nl', $fonds, null, '3');
        $this->draft = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Ongepubliseerde brief', 'af', $fonds, null, '4');
        $maria = app(AgentService::class)->create($this->institution, 'person', 'Botha, Maria', 'en');
        foreach ([$this->letter, $this->diary] as $unit) {
            app(RelationService::class)->create($unit, 'creation', $maria, [], $this->admin);
        }
        foreach ([$holder, $fonds, $this->letter, $this->diary, $dutch, $maria] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->admin);
        }
        app(SearchIndex::class)->flush();
        Http::fake(function (ClientRequest $request) {
            $this->asked[] = $request->data()['messages'][1]['content'] ?? '';

            return Http::response(['model' => 'qwen3:8b', 'message' => ['content' => "- Dagboek van die boer and Brief oor die brug were both created by Botha, Maria - worth comparing.\n- No non-obvious connections found in the supplied links."]]);
        });
    }

    public function test_the_languages_of_the_collection_and_what_people_contribute(): void
    {
        $languages = app(Corpus::class)->languages($this->institution);
        $this->assertSame(['en', 'af', 'nl'], array_column($languages, 'code'), 'the richest first, then Afrikaans moved to just before Dutch, as Heratio orders them');
        $this->assertSame(2, $languages[1]['records'], 'a draft is not counted');
        $this->get(route('discover.languages.show', 'af'))->assertOk()->assertSee('Brief oor die brug')->assertDontSee('Ongepubliseerde brief');

        $this->post(route('discover.languages.glossary', 'af'), ['term' => 'brug', 'meaning' => 'bridge', 'contributor_name' => 'Anna'])->assertRedirect();
        $this->get(route('discover.languages.show', 'af'))->assertDontSee('bridge');
        $this->post(route('discover.transcribe.store', $this->letter->id), ['type' => 'transcription', 'body' => 'Liewe Ma, die brug is klaar.', 'contributor_name' => 'Piet'])->assertRedirect();
        $this->post(route('discover.transcribe.store', $this->letter->id), ['type' => 'translation', 'language' => 'en', 'body' => 'Dear Ma, the bridge is done.', 'contributor_name' => 'Sara', 'credit_consent' => '1']);
        $this->get(route('discover.transcribe', $this->draft->id))->assertNotFound();
        $this->assertSame([null, 'af'], [LanguageContribution::where('body', 'like', 'Liewe%')->value('contributor_name'), LanguageContribution::where('body', 'like', 'Liewe%')->value('language')],
            'no name without consent; the title\'s language by default');

        $this->actingAs($this->editor)->get(route('languages.review'))->assertOk()->assertSee('Liewe Ma, die brug is klaar.')->assertSee('bridge');
        foreach (LanguageContribution::all() as $c) {
            $this->actingAs($this->editor)->post(route('languages.moderate.contribution', $c), ['status' => 'approved'])->assertRedirect();
        }
        $this->actingAs($this->editor)->post(route('languages.moderate.glossary', LanguageGlossaryEntry::sole()), ['status' => 'approved']);
        auth()->logout();
        $this->get(route('discover.languages.show', 'af'))->assertSee('bridge')->assertSee('Liewe Ma, die brug is klaar.');
        $this->get(route('discover.transcribe', $this->letter->id))->assertSee('Dear Ma, the bridge is done.')->assertSee('Sara')->assertSee('Anonymous contributor');
    }

    public function test_a_model_draws_leads_only_from_the_published_links(): void
    {
        $plain = app(Scholarship::class)->discover($this->diary);
        $this->assertSame([[], false, ['Botha family papers', 'Botha, Maria', 'Riverside Archives']], [$plain['insights'], $plain['ai_available'],
            collect($plain['connections'])->flatMap(fn ($g) => array_column($g['items'], 'name'))->sort()->values()->all()], 'AI off: the links stand, nothing asked');
        $this->assertSame([], $this->asked);

        AiSetting::create(['institution_id' => $this->institution->id, 'enabled' => true]);
        $discovery = app(Scholarship::class)->persist($this->diary, $this->admin);
        $this->assertStringContainsString('Botha, Maria', $this->asked[0]);
        $this->assertStringContainsString('Brief oor die brug', $this->asked[0], 'the second step, reached through the creator');
        $this->assertStringNotContainsString('Ongepubliseerde', $this->asked[0]);
        $this->assertSame(['Dagboek van die boer and Brief oor die brug were both created by Botha, Maria - worth comparing.'], $discovery->evidence['insights']);
        $this->assertNotNull($discovery->inference_id);
        $this->assertSame(Scholarship::confidence($discovery->connection_count, $discovery->evidence['second_hop_count'], $discovery->evidence['grounded_entities'], 1), $discovery->confidence);

        $contributor = app(AccountService::class)->create($this->institution, 'Contributor', 'contributor@riverside.example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($contributor, 'contributor');
        $this->actingAs($contributor)->get(route('scholarship.index'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('scholarship.show', $this->diary))->assertOk()->assertSee('worth comparing');
        $this->actingAs($this->admin)->post(route('scholarship.leads.generate'), ['limit' => 10])->assertRedirect();
        $lead = ResearchLead::sole();
        $this->assertSame(['pending', 'Dagboek van die boer'], [$lead->status->notation, $lead->headline]);
        $this->assertStringContainsString('verified catalogue connection', $lead->why_it_matters);

        auth()->logout();
        $this->get(route('discover.research-leads'))->assertOk()->assertDontSee('Dagboek van die boer');
        $this->actingAs($this->admin)->post(route('scholarship.leads.curate', [$lead, 'published']))->assertRedirect();
        auth()->logout();
        $this->get(route('discover.research-leads'))->assertSee('Dagboek van die boer');
        $this->get(route('discover.research-leads.show', $lead->id))->assertOk()->assertSee('Botha, Maria');
        $this->get(route('discover.record', $this->diary->id))->assertSee(route('discover.research-leads.show', $lead->id), false);
        app(PublicationService::class)->apply($this->diary, 'withdraw', null, false, $this->admin);
        $this->get(route('discover.research-leads.show', $lead->id))->assertNotFound();
    }

    public function test_federation_peers_holding_what_shares_a_records_access_points(): void
    {
        [$hillside, $hillsideAdmin] = $this->institution('Hillside Archives', 'hillside');
        $holder = app(RepositoryService::class)->create($hillside, 'Hillside Archives', 'en', 'HA');
        $copy = app(RecordService::class)->create($hillside, self::LEVEL.'item', 'Copy of a letter by Botha, Maria', 'en', null, $holder, 'H1');
        foreach ([$holder, $copy] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $hillsideAdmin);
        }
        app(SearchIndex::class)->flush();
        FederationShareSetting::for($hillside->id)->fill(['share_enabled' => true])->save();
        $this->actingAs($this->admin)->post(route('federation.peers.store'), ['name' => 'Hillside', 'type' => 'institution', 'peer_institution_id' => $hillside->id,
            'is_active' => 1, 'search_enabled' => 1])->assertSessionHasNoErrors();
        AiSetting::create(['institution_id' => $this->institution->id, 'enabled' => true]);

        $found = app(Scholarship::class)->federated($this->diary, true, $this->admin);
        $this->assertSame('Copy of a letter by Botha, Maria', $found['connections'][0]['title']);
        $this->assertContains('Botha, Maria', $found['connections'][0]['shared']);
        $this->assertSame(1, ScholarshipFederatedDiscovery::count());
        $this->assertTrue(app(Scholarship::class)->federated($this->diary)['cached'], 'kept for a day');
    }

    /** @return array{Institution, User} */
    private function institution(string $name, string $host): array
    {
        $institution = Institution::create(['name' => $name, 'base_iri' => "https://{$host}.example.org"]);
        app(RoleService::class)->seedDefaults($institution);
        $admin = app(AccountService::class)->create($institution, 'Admin', "admin@{$host}.example.org", 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($admin, 'administrator');

        return [$institution, $admin];
    }
}
