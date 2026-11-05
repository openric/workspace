<?php

// OpenRiC Workspace - search by meaning, widening, reranking, grounding and ways in (roadmap phase 17d1), after
// Heratio's ahg-semantic-search, against a gateway whose embedding model puts words of one meaning ("bridge",
// "crossing") on the same dimension: the index's entries are turned into vectors and kept beside them; a query by
// meaning finds a unit that holds none of its words, which a search by the words does not, and both together find
// both; a search is widened by the other labels of a concept its words name; a page is reranked by an account's recent
// searches; a unit's related units are its nearest; /ground answers with packs of public facts and a prompt-ready
// block; the ways in count only what is published; and nothing is asked of the gateway while search by meaning is off.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\AgentService;
use App\Description\DateService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\AiSetting;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use App\Relations\RelationService;
use App\Search\Meaning;
use App\Search\SearchIndex;
use App\Search\SearchService;
use App\Vocabulary\ConceptService;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SearchByMeaningTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    /** Words of one meaning share a dimension of the fake model's vectors. */
    private const SENSE = ['crossing' => 'bridge', 'crossings' => 'bridge', 'bridges' => 'bridge', 'viaduct' => 'bridge'];

    private Institution $institution;

    private User $admin;

    private RecordResource $bridge;

    private RecordResource $school;

    /** @var list<string> the gateway routes asked */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        config(['workspace.ai.gateway_key' => 'test-key', 'workspace.ai.gateway_url' => 'https://ai.example.org/ai/v1']);
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://riverside.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->admin = app(AccountService::class)->create($this->institution, 'Admin', 'admin@riverside.example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->admin, 'administrator');
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $fonds = app(RecordService::class)->create($this->institution, self::LEVEL.'fonds', 'Riverside Parish Council records', 'en', null, $holder, 'RPC');
        $this->bridge = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Plans for the new bridge', 'en', $fonds, null, '1');
        app(RecordService::class)->describe($this->bridge, ['scope-and-content' => 'Drawings of the bridge over the river, 1902.'], 'en');
        $this->school = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'School register', 'en', $fonds, null, '2');
        app(RecordService::class)->describe($this->school, ['scope-and-content' => 'Pupils admitted to the parish school.'], 'en');
        app(DateService::class)->add($this->bridge, ['property' => 'creation', 'normalized' => '1902']);
        app(DateService::class)->add($this->school, ['property' => 'creation', 'normalized' => '1951']);
        $clerk = app(AgentService::class)->create($this->institution, 'person', 'Botha, Maria', 'en');
        app(RelationService::class)->create($this->bridge, 'creation', $clerk, [], $this->admin);
        $subjects = app(ConceptService::class)->createScheme($this->institution, 'subject', 'Local subjects', 'en', null, $this->admin);
        $bridges = app(ConceptService::class)->create($subjects, ['label' => 'Bridges', 'language' => 'en', 'alt_labels' => "Crossings\nViaducts"], $this->admin);
        app(RelationService::class)->create($this->bridge, 'subject', $bridges, [], $this->admin);
        foreach ([$holder, $fonds, $this->bridge, $this->school, $clerk] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->admin);
        }
        app(SearchIndex::class)->flush();
        Http::fake(function (ClientRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $this->asked[] = $path;
            if ($path === '/ai/v1/ollama/api/embed') {
                return Http::response(['model' => 'nomic-embed-text', 'embeddings' => array_map(fn ($t) => self::vector($t), $request->data()['input'])]);
            }

            return Http::response(['model' => 'qwen3:8b', 'message' => ['content' => 'viaduct, river crossing']]);
        });
    }

    public function test_entries_are_found_by_meaning_where_their_words_are_not(): void
    {
        $search = app(SearchService::class);
        $this->assertSame('words', $search->search($this->institution, 'crossing', true, [], 1, 20, ['mode' => 'meaning'])['mode'], 'off until switched on');
        $this->assertSame([], $this->asked, 'nothing asked while search by meaning is off');

        $this->on();
        $this->assertSame(DB::table('search_entries')->where('institution_id', $this->institution->id)->count(), app(Meaning::class)->index($this->institution), 'every entry, as the public and as staff see it');
        $this->assertSame(0, app(Meaning::class)->index($this->institution), 'nothing changed, nothing embedded again');
        app(RecordService::class)->describe($this->school, ['scope-and-content' => 'Pupils admitted to the school by the river.'], 'en');
        app(SearchIndex::class)->flush();
        $this->assertGreaterThan(0, app(Meaning::class)->index($this->institution), 'a changed entry is embedded again');

        $words = $search->search($this->institution, 'crossing', true, ['kind' => 'records'], 1, 20, ['mode' => 'words']);
        $this->assertSame([], $words['results'], 'no unit holds the word');
        $meaning = $search->search($this->institution, 'crossing', true, ['kind' => 'records'], 1, 20, ['mode' => 'meaning']);
        $this->assertSame('meaning', $meaning['mode']);
        $this->assertSame($this->bridge->id, $meaning['results'][0]['id'], 'the bridge plans are nearest a crossing');
        $both = $search->search($this->institution, 'school crossing', true, ['kind' => 'records'], 1, 20, ['mode' => 'both']);
        $this->assertEqualsCanonicalizing([$this->bridge->id, $this->school->id], array_slice(array_column($both['results'], 'id'), 0, 2));
        $this->assertSame(['records' => 1], $meaning['facets']['kind'], 'only what is alike enough is found, and counted');

        $this->get(route('discover.home', ['q' => 'crossing', 'mode' => 'meaning']))->assertOk()->assertSee('Plans for the new bridge')->assertSee('Found by meaning');
        $this->assertStringContainsString('ollama/api/embed', implode(' ', $this->asked));
        $this->assertGreaterThan(0, DB::table('ai_inferences')->where('field', 'search-query')->count(), 'each query embedding is a receipt');
    }

    public function test_a_search_is_widened_by_the_labels_of_the_concept_its_words_name(): void
    {
        $result = app(SearchService::class)->search($this->institution, 'viaducts', true, ['kind' => 'records'], 1, 20, ['mode' => 'words']);
        $this->assertEqualsCanonicalizing(['Bridges', 'Crossings'], $result['expanded']);
        $this->assertSame([$this->bridge->id], array_column($result['results'], 'id'), 'found by "bridges", which the concept also names');
        $this->get(route('discover.home', ['q' => 'viaducts']))->assertOk()->assertSee('Also searched for: Bridges, Crossings', false);
        DB::table('search_settings')->insert(['institution_id' => $this->institution->id, 'expand_synonyms' => false, 'expansion_limit' => 5, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame([], app(SearchService::class)->search($this->institution, 'viaducts', true, [], 1, 20)['results'], 'not widened once switched off');
    }

    public function test_a_page_is_reranked_by_an_accounts_recent_searches(): void
    {
        DB::table('search_settings')->insert(['institution_id' => $this->institution->id, 'rerank_history' => true, 'expand_synonyms' => false, 'expansion_limit' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $search = app(SearchService::class);
        $plain = array_column($search->search($this->institution, 'parish', false, ['kind' => 'records'], 1, 20)['results'], 'id');
        foreach (['school pupils', 'school register', 'the school'] as $q) {
            $search->search($this->institution, $q, false, [], 1, 20, ['user' => $this->admin]);
        }
        $reranked = array_column($search->search($this->institution, 'parish', false, ['kind' => 'records'], 1, 20, ['user' => $this->admin])['results'], 'id');
        $this->assertSame($this->school->id, $reranked[0], 'what the account has been searching for comes first');
        $this->assertEqualsCanonicalizing($plain, $reranked, 'the same results, reordered');

        foreach (range(1, 30) as $i) {
            $search->search($this->institution, "query {$i}", false, [], 1, 20, ['user' => $this->admin]);
        }
        $this->assertSame(25, DB::table('search_history')->where('user_id', $this->admin->id)->count(), 'the last 25 searches only');
        DB::table('search_settings')->update(['rerank_history' => false]);
        $search->search($this->institution, 'not kept', false, [], 1, 20, ['user' => $this->admin]);
        $this->assertFalse(DB::table('search_history')->where('query', 'not kept')->exists(), 'nothing is kept while reranking is off');
    }

    public function test_related_units_and_grounding_rest_on_what_the_public_sees(): void
    {
        $this->on();
        app(Meaning::class)->index($this->institution);
        $related = app(Meaning::class)->related('record_resources', $this->bridge->id, true);
        $this->assertNotContains($this->bridge->id, array_column($related, 'subject_id'));
        $this->get(route('discover.record', $this->bridge->id))->assertOk()->assertSee('Related by meaning');

        $this->getJson(route('api.ground'))->assertStatus(422);
        $ground = $this->getJson(route('api.ground', ['q' => 'bridge plans', 'max' => 2]))->assertOk()->json();
        $pack = collect($ground['entities'])->firstWhere('iri', $this->bridge->iri);
        $this->assertSame('Plans for the new bridge', $pack['label']);
        $this->assertNotEmpty($pack['types']);
        $this->assertStringStartsWith("Authoritative facts from the archive's knowledge graph", $ground['grounding_text']);
        $this->assertStringContainsString($this->bridge->iri, $ground['grounding_text']);
    }

    public function test_the_ways_in_count_only_what_is_published(): void
    {
        $draft = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Unpublished letter', 'en', null, $this->bridge->repository ?? $this->bridge->parent->repository, '3');
        $bridges = DB::table('concepts')->join('concept_labels as l', 'l.concept_id', '=', 'concepts.id')->where('l.pref_label', 'Bridges')->value('concepts.id');
        app(RelationService::class)->create($draft, 'subject', Concept::find($bridges), [], $this->admin);
        app(SearchIndex::class)->flush();

        $this->get(route('discover.ways-in'))->assertOk()->assertSee('Bridges')->assertSee('Botha, Maria')->assertSee('1900 to 1999');
        $this->get(route('discover.ways-in.units', ['themes', $bridges]))->assertOk()->assertSee('Plans for the new bridge')->assertDontSee('Unpublished letter')->assertSee('1 description');
        $this->get(route('discover.ways-in.facet', 'people'))->assertOk()->assertSee('Botha, Maria');
        $this->get(route('discover.ways-in.timeline'))->assertOk()->assertSee('1900 to 1909')->assertSee('1950 to 1959');
        $this->get(route('discover.ways-in.decade', 1950))->assertOk()->assertSee('School register')->assertDontSee('Plans for the new bridge');
    }

    public function test_search_by_meaning_is_set_up_by_administrators(): void
    {
        AiSetting::create(['institution_id' => $this->institution->id, 'enabled' => true]);
        $this->actingAs($this->admin)->post(route('ai.save'), ['enabled' => 1, 'search' => ['by_meaning' => 1, 'expand_synonyms' => 1, 'expand_with_model' => 1, 'expansion_limit' => 3]])->assertRedirect();
        $settings = app(Meaning::class)->settings($this->institution->id);
        $this->assertSame([true, true, 3], [(bool) $settings->by_meaning, (bool) $settings->expand_with_model, (int) $settings->expansion_limit]);
        $result = app(SearchService::class)->search($this->institution, 'viaducts', true, ['kind' => 'records'], 1, 20);
        $this->assertSame(['Bridges', 'Crossings', 'viaduct'], array_values(array_unique([...array_intersect(['Bridges', 'Crossings'], $result['expanded']), ...array_diff($result['expanded'], ['Bridges', 'Crossings'])])), 'a model\'s terms join the vocabulary\'s, to the limit');
        $this->assertCount(3, $result['expanded']);
        $this->actingAs($this->admin)->get(route('ai.index'))->assertOk()->assertSee('Search by meaning as well as by the words', false);
    }

    private function on(): void
    {
        AiSetting::create(['institution_id' => $this->institution->id, 'enabled' => true]);
        DB::table('search_settings')->insert(['institution_id' => $this->institution->id, 'by_meaning' => true, 'expand_synonyms' => false, 'expansion_limit' => 0,
            'min_similarity' => 0.2, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** A bag of words in 768 dimensions, words of one meaning on one dimension, made unit length. @return list<float> */
    private static function vector(string $text): array
    {
        $v = array_fill(0, 768, 0.0);
        preg_match_all('/\p{L}+/u', mb_strtolower($text), $m);
        foreach ($m[0] as $word) {
            $v[crc32(self::SENSE[$word] ?? $word) % 768] += 1.0;
        }
        $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $v))) ?: 1.0;

        return array_map(fn ($x) => $x / $norm, $v);
    }
}
