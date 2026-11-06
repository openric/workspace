<?php

// OpenRiC Workspace - subject suggestions by Annif (roadmap phase 17e), against a gateway that answers as its annif route
// will (Annif's REST API, gateway issue ahg-ai#4): nothing is asked until AI is on and a project and vocabulary are
// chosen; each suggestion of the vocabulary that the record does not have waits for review with its score and receipt,
// one outside the vocabulary or already its subject left out; an accepted one is the record's subject, the model's
// assertion reviewed; accepted subjects are taught back once; and a trial measures precision and recall.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Ai\Annif;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\AiSetting;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\Proposal;
use App\Models\RecordResource;
use App\Models\Relation;
use App\Models\User;
use App\Models\Vocabulary;
use App\Proposals\ReviewService;
use App\Relations\RelationService;
use App\Vocabulary\ConceptService;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnnifTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private Institution $institution;

    private User $admin;

    private RecordResource $letter;

    private Vocabulary $subjects;

    private Concept $bridges;

    private Concept $schools;

    /** @var list<array{path: string, data: array}> */
    private array $sent = [];

    /** @var list<string> the IRIs the fake Annif suggests */
    private array $suggests = [];

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
        $this->letter = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Letter about the parish bridge and school', 'en', null, $holder, 'RA1');
        app(RecordService::class)->describe($this->letter, ['scope-and-content' => 'The council asks for a bridge over the river near the school.'], 'en');
        $this->subjects = app(ConceptService::class)->createScheme($this->institution, 'subject', 'Local subjects', 'en', null, $this->admin);
        $this->bridges = app(ConceptService::class)->create($this->subjects, ['label' => 'Bridges', 'language' => 'en'], $this->admin);
        $this->schools = app(ConceptService::class)->create($this->subjects, ['label' => 'Schools', 'language' => 'en'], $this->admin);
        app(RelationService::class)->create($this->letter, 'subject', $this->schools, [], $this->admin);
        foreach ([$holder, $this->letter] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->admin);
        }
        $this->suggests = [$this->bridges->iri, $this->schools->iri, 'http://www.yso.fi/onto/yso/p1234'];
        Http::fake(function (ClientRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $this->sent[] = ['path' => $path, 'data' => $request->data()];
            if (str_ends_with($path, '/learn')) {
                return Http::response([]);
            }

            return Http::response(['results' => array_map(fn ($uri, $i) => ['uri' => $uri, 'label' => 'x', 'score' => round(0.9 - $i * 0.2, 2)], $this->suggests, array_keys($this->suggests))]);
        });
    }

    public function test_suggestions_wait_for_review_and_become_subjects_once_accepted(): void
    {
        $this->assertFalse(app(Annif::class)->configured($this->institution->id));
        $this->actingAs($this->admin)->post(route('records.ask', [$this->letter, 'subjects']))->assertSessionHasErrors('ai');
        $this->actingAs($this->admin)->post(route('ai.save'), ['enabled' => '1', 'annif_project' => 'openric-riverside-subjects', 'annif_vocabulary_id' => $this->subjects->id,
            'annif_limit' => 5, 'annif_threshold' => 0.2])->assertRedirect();
        $this->assertTrue(app(Annif::class)->configured($this->institution->id));
        $this->actingAs($this->admin)->get(route('records.show', $this->letter))->assertSee('Suggest subjects with Annif');

        $this->assertSame(1, app(Annif::class)->propose($this->letter, $this->admin), 'Schools it has already, and the outside concept is not in the vocabulary');
        $this->assertSame('/ai/v1/annif/v1/projects/openric-riverside-subjects/suggest', $this->sent[0]['path']);
        $this->assertStringContainsString('near the school', $this->sent[0]['data']['text']);
        $proposal = Proposal::sole();
        $this->assertSame(['subject', 'Bridges', $this->bridges->id, 0.9], [$proposal->kind->notation, $proposal->value, $proposal->linked_id, $proposal->confidence]);
        $this->assertNotNull($proposal->inference_id);
        $this->assertSame(0, app(Annif::class)->propose($this->letter, $this->admin), 'the same suggestion is not proposed twice');
        $this->actingAs($this->admin)->get(route('proposals.index'))->assertSee('Bridges')->assertSee('Local subjects');

        app(ReviewService::class)->accept($proposal, $this->admin, []);
        $relation = Relation::where(['source_id' => $this->letter->id, 'target_id' => $this->bridges->id])->sole();
        $this->assertSame(['subject', 'model', 'accepted'], [$relation->relationType->notation, $relation->assertions()->first()->origin->notation, $relation->standing()]);

        $this->assertSame(1, app(Annif::class)->learn($this->institution));
        $learn = collect($this->sent)->firstWhere('path', '/ai/v1/annif/v1/projects/openric-riverside-subjects/learn');
        $this->assertSame([$this->bridges->iri], array_column($learn['data']['documents'][0]['subjects'], 'uri'));
        $this->assertSame(0, app(Annif::class)->learn($this->institution), 'taught once');
    }

    public function test_a_trial_measures_precision_and_recall_on_published_subjects(): void
    {
        AiSetting::create(['institution_id' => $this->institution->id, 'enabled' => true, 'annif_project' => 'openric-riverside-subjects', 'annif_vocabulary_id' => $this->subjects->id]);
        $r = app(Annif::class)->trial($this->institution, $this->admin, 5);
        $this->assertSame([1, 0.5, 1.0], [$r['records'], round($r['precision'], 2), round($r['recall'], 2)], 'two of the vocabulary suggested, one of them its subject');
        $this->assertSame(1, DB::table('annif_trials')->count());
        $this->actingAs($this->admin)->get(route('ai.index'))->assertSee('openric-riverside-subjects')->assertSee('0.50');
    }
}
