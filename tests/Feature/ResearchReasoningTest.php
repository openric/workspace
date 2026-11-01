<?php

// OpenRiC Workspace - the reasoning of a research project: claims and their evidence, assertions reviewed together,
// the knowledge graph, hypotheses, the contradiction engine, the decision log, research memory and analysis results
// (roadmap phase 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\AgentService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Agent;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\ResearchClaim;
use App\Models\ResearchContradiction;
use App\Models\ResearchHypothesis;
use App\Models\ResearchMemoryItem;
use App\Models\ResearchProject;
use App\Models\User;
use App\Research\Collections;
use App\Research\Projects;
use App\Search\SearchIndex;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ResearchReasoningTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private string $store;

    private Institution $institution;

    private User $admin;

    private User $reader;

    private User $other;

    private RecordResource $letter;

    private Agent $engineer;

    private ResearchProject $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        Mail::fake();
        $this->travelTo('2026-11-06 09:00');
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->admin = app(AccountService::class)->create($this->institution, 'Admin', 'admin@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->admin, 'administrator');
        $this->reader = app(AccountService::class)->create($this->institution, 'Rita', 'rita@example.org', 'correct horse battery staple');
        $this->other = app(AccountService::class)->create($this->institution, 'Olu', 'olu@example.org', 'correct horse battery staple');
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $fonds = app(RecordService::class)->create($this->institution, self::LEVEL.'fonds', 'Parish council records', 'en', null, $holder, 'PC');
        $this->letter = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Letter about the parish bridge', 'en', $fonds, null, '1');
        $this->engineer = app(AgentService::class)->create($this->institution, 'person', 'Jan Smit', 'en', null, $this->admin);
        foreach ([$holder, $fonds, $this->letter, $this->engineer] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->admin);
        }
        app(SearchIndex::class)->flush();
        $this->project = app(Projects::class)->create($this->reader, ['title' => 'The parish bridge', 'visibility' => 'collaborators']);
        $member = app(Projects::class)->invite($this->reader, $this->project, 'olu@example.org', 'contributor');
        app(Projects::class)->answer($this->other, $member->fresh(['status']), true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->store)) {
            exec('rm -rf '.escapeshellarg($this->store));
        }
        parent::tearDown();
    }

    private function claim(User $who, array $data): ResearchClaim
    {
        $this->actingAs($who)->post(route('research.reasoning.claims.store', $this->project), $data)->assertSessionHasNoErrors();

        return ResearchClaim::where('object_value', $data['object_value'])->sole();
    }

    public function test_claims_carry_their_evidence_and_unsupported_ones_do_not_pass_silently(): void
    {
        $this->actingAs($this->admin)->get(route('research.reasoning.claims', $this->project))->assertNotFound();
        $this->actingAs($this->other)->post(route('research.reasoning.claims.store', $this->project), ['object_value' => ''])->assertSessionHasErrors();
        $built = $this->claim($this->other, ['object_value' => 'The iron bridge was built in 1898 by Jan Smit.', 'claim_type' => 'chronological', 'status' => 'working',
            'confidence_level' => 'high', 'subject_iri' => $this->engineer->iri, 'predicate' => 'built', 'object_label' => 'the iron bridge', 'quotations' => '"completed this year" (letter of 1898)']);
        $this->assertSame([$this->engineer->id, 'Jan Smit', 0.85], [$built->subject_id, $built->subject_label, $built->confidence]);
        $this->claim($this->other, ['object_value' => 'The council paid for the bridge from rates.', 'status' => 'idea']);

        // Evidence: a record by IRI, an item of the project's collection, a bibliography entry, another claim; not itself, not twice.
        $collection = app(Collections::class)->save($this->reader, ['name' => 'Bridge sources']);
        $item = app(Collections::class)->add($this->reader, $collection, $this->letter);
        app(Projects::class)->assign($this->reader, $collection, $this->project);
        $step = fn (array $data, ?User $who = null) => $this->actingAs($who ?? $this->other)->post(route('research.reasoning.claims.step', [$this->project, $built, 'evidence']), $data);
        $step(['source_type' => 'record', 'iri' => $this->letter->iri, 'relationship' => 'supports', 'note' => 'The letter names him'])->assertSessionHasNoErrors();
        $step(['source_type' => 'record', 'iri' => $this->letter->iri, 'relationship' => 'supports'])->assertSessionHasErrors('research');
        $step(['source_type' => 'collection-item', 'source_id' => $item->id, 'relationship' => 'supports'])->assertSessionHasNoErrors();
        $step(['source_type' => 'claim', 'source_id' => $built->id])->assertSessionHasErrors('research');
        $outside = app(Collections::class)->save($this->reader, ['name' => 'Not the project\'s']);
        $step(['source_type' => 'collection-item', 'source_id' => app(Collections::class)->add($this->reader, $outside, $this->letter)->id])->assertSessionHasErrors('research');
        $this->assertSame(2, $built->evidence()->count());

        $this->actingAs($this->reader)->get(route('research.reasoning.claims', $this->project))->assertOk()->assertSee('1 claim has no evidence.')->assertDontSee('rests on one source alone');
        $this->actingAs($this->reader)->get(route('research.reasoning.claim', [$this->project, $built]))->assertOk()->assertSee('Letter about the parish bridge')->assertSee('The letter names him')
            ->assertSee('completed this year');

        // Changing the words or status raises the version; editing without naming the subject again keeps it.
        $this->actingAs($this->other)->post(route('research.reasoning.claims.update', [$this->project, $built]), ['object_value' => 'The iron bridge was built in 1898 by Jan Smit.',
            'claim_type' => 'chronological', 'status' => 'supported', 'subject_label' => 'Jan Smit', 'predicate' => 'built', 'object_label' => 'the iron bridge'])->assertSessionHasNoErrors();
        $this->assertSame([2, $this->engineer->id], [$built->fresh()->version, $built->fresh()->subject_id]);

        $graph = $this->actingAs($this->reader)->getJson(route('research.reasoning.graph', $this->project))->assertOk()->json();
        $this->assertSame([['source' => $this->engineer->id, 'target' => 'label:the iron bridge', 'label' => 'built', 'claim' => $built->id, 'status' => 'supported']], $graph['edges']);
        $this->assertSame(2, collect($graph['nodes'])->firstWhere('id', $this->engineer->id)['evidence']);

        // Proposed assertions reviewed together by an editor.
        $proposed = $this->claim($this->other, ['object_value' => 'Jan Smit was born in Utrecht.', 'claim_type' => 'biographical', 'status' => 'proposed']);
        $this->actingAs($this->other)->post(route('research.reasoning.claims.review', $this->project), ['ids' => [$proposed->id], 'status' => 'verified'])->assertSessionHasErrors('research');
        $this->actingAs($this->reader)->post(route('research.reasoning.claims.review', $this->project), ['ids' => [$proposed->id, $built->id], 'status' => 'verified'])
            ->assertSessionHas('status', '1 assertion was changed.');
        $this->assertSame('verified', $proposed->fresh()->status->notation);

        $this->actingAs($this->reader)->post(route('research.reasoning.claims.step', [$this->project, $proposed, 'delete']))->assertRedirect(route('research.reasoning.claims', $this->project));
        $this->assertSame(2, ResearchClaim::count());
    }

    public function test_the_contradiction_engine_finds_four_kinds_and_keeps_each_once(): void
    {
        $built = $this->claim($this->reader, ['object_value' => 'The iron bridge over the river was completed in 1898.', 'status' => 'supported']);
        $notBuilt = $this->claim($this->reader, ['object_value' => 'The iron bridge over the river was never completed.', 'status' => 'rejected']);
        $weak = $this->claim($this->reader, ['object_value' => 'Councillors opposed the toll.', 'status' => 'working', 'confidence' => 0.9]);
        $this->actingAs($this->reader)->post(route('research.reasoning.claims.update', [$this->project, $weak]), ['object_value' => 'Councillors opposed the toll.', 'status' => 'weak', 'confidence' => 0.9])
            ->assertSessionHasNoErrors();
        $this->claim($this->reader, ['object_value' => 'Tolls paid for the upkeep.', 'status' => 'working', 'confidence' => 0.2, 'confidence_level' => 'high']);
        foreach ([[$built, 'supports'], [$notBuilt, 'refutes']] as [$claim, $relationship]) {
            $this->actingAs($this->reader)->post(route('research.reasoning.claims.step', [$this->project, $claim, 'evidence']), ['source_type' => 'record', 'iri' => $this->letter->iri, 'relationship' => $relationship])
                ->assertSessionHasNoErrors();
        }
        $this->claim($this->reader, ['object_value' => 'Smitsdrif means the smith\'s ford.', 'status' => 'verified']);
        $this->claim($this->reader, ['object_value' => 'Smitsdrif was a farm.', 'status' => 'disputed']);

        $this->actingAs($this->admin)->post(route('research.reasoning.contradictions.scan', $this->project))->assertSessionHasErrors('research');
        $this->actingAs($this->other)->post(route('research.reasoning.contradictions.scan', $this->project))->assertSessionHasNoErrors();
        $kinds = ResearchContradiction::with('kind')->get()->countBy(fn ($f) => $f->kind->notation)->all();
        ksort($kinds);
        $this->assertSame(['confidence-drop' => 2, 'definition-drift' => 1, 'opposing-status' => 1, 'shared-source-conflict' => 1], $kinds);
        // Scanned again, each is kept once; dismissed, it stays dismissed.
        $this->actingAs($this->other)->post(route('research.reasoning.contradictions.scan', $this->project))->assertSessionHasNoErrors();
        $this->assertSame(5, ResearchContradiction::count());
        $drift = ResearchContradiction::whereHas('kind', fn ($k) => $k->where('notation', 'definition-drift'))->sole();
        $this->actingAs($this->other)->post(route('research.reasoning.contradictions.status', [$drift, 'dismissed']))->assertSessionHasNoErrors();
        $this->actingAs($this->other)->post(route('research.reasoning.contradictions.scan', $this->project))->assertSessionHasNoErrors();
        $this->assertSame('dismissed', $drift->fresh()->status->notation);
        $this->actingAs($this->reader)->get(route('research.reasoning.contradictions', $this->project))->assertOk()->assertSee('One source cannot do both')->assertDontSee('Smitsdrif');
    }

    public function test_hypotheses_decisions_memory_and_analysis_results(): void
    {
        $this->actingAs($this->other)->post(route('research.reasoning.hypotheses.save', $this->project), ['statement' => 'The bridge was funded by tolls.', 'tags' => 'tolls, funding'])->assertSessionHasNoErrors();
        $hypothesis = ResearchHypothesis::sole();
        $this->actingAs($this->other)->post(route('research.reasoning.hypotheses.step', [$hypothesis, 'evidence']), ['source_type' => 'record', 'iri' => $this->letter->iri, 'relationship' => 'neutral', 'confidence' => 0.4])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, $hypothesis->fresh()->evidence_count);
        $this->actingAs($this->reader)->post(route('research.reasoning.hypotheses.save', [$this->project, $hypothesis]), ['statement' => 'The bridge was funded by tolls.', 'status' => 'refuted'])->assertSessionHasNoErrors();
        $this->actingAs($this->reader)->get(route('research.reasoning.hypotheses', $this->project))->assertOk()->assertSee('Refuted')->assertSee('Letter about the parish bridge');

        // The decision log, and research memory suggested from it.
        $this->actingAs($this->other)->post(route('research.reasoning.decisions.save', $this->project), ['decision_type' => 'exclusion', 'summary' => 'Leave out the 1902 survey',
            'reason' => 'It covers the road, not the bridge.', 'decided_at' => '2026-11-01'])->assertSessionHasNoErrors();
        $this->actingAs($this->other)->post(route('research.reasoning.decisions.save', $this->project), ['decision_type' => 'scope-change', 'summary' => 'Only the iron bridge'])->assertSessionHasNoErrors();
        $this->actingAs($this->reader)->get(route('research.reasoning.decisions', [$this->project, 'type' => 'exclusion']))->assertOk()->assertSee('Leave out the 1902 survey')->assertDontSee('Only the iron bridge');
        $page = $this->actingAs($this->reader)->get(route('research.reasoning.memory', ['project' => $this->project->id]))->assertOk()->assertSee('Leave out the 1902 survey');
        $signature = 'decision:'.$this->project->decisions()->where('summary', 'Leave out the 1902 survey')->value('id');
        $this->actingAs($this->reader)->post(route('research.reasoning.memory.step', ['accept']), ['project_id' => $this->project->id, 'signature' => $signature])->assertSessionHasNoErrors();
        $kept = ResearchMemoryItem::sole();
        $this->assertSame(['unused-source', $this->project->id], [$kept->kind->notation, $kept->project_id]);
        $this->actingAs($this->reader)->get(route('research.reasoning.memory', ['project' => $this->project->id]))->assertOk()->assertDontSee('Keep it');
        $this->actingAs($this->reader)->post(route('research.reasoning.memory.save', $kept), ['title' => $kept->title, 'kind' => 'unused-source', 'status' => 'carried-forward', 'project_id' => $this->project->id])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->reader)->get(route('research.reasoning.memory'))->assertOk()->assertSee('Carried forward');
        $this->actingAs($this->other)->post(route('research.reasoning.memory.step', ['delete', $kept]))->assertSessionHasErrors('research');

        // Analysis results with their file and the claims they bear on; theme tags and memos.
        $claim = $this->claim($this->other, ['object_value' => 'Toll income rose after 1900.', 'status' => 'working']);
        $this->actingAs($this->other)->post(route('research.reasoning.analysis.save', $this->project), ['title' => 'Toll income 1895-1910', 'result_type' => 'chart', 'method' => 'Yearly sums of the toll books',
            'code_ref' => 'tolls.ipynb', 'artifact' => UploadedFile::fake()->createWithContent('tolls.csv', "year,income\n1899,40\n1901,65")])->assertSessionHasNoErrors();
        $result = $this->project->analysisResults()->sole();
        $this->actingAs($this->other)->post(route('research.reasoning.analysis.step', [$result, 'claim']), ['claim_id' => $claim->id, 'relationship' => 'supports'])->assertSessionHasNoErrors();
        $this->assertSame('supports', $result->claimLinks()->sole()->relationship->notation);
        $this->assertSame("year,income\n1899,40\n1901,65", $this->actingAs($this->reader)->get(route('research.reasoning.analysis.step', [$result, 'artifact']))->assertOk()->streamedContent());
        $this->actingAs($this->admin)->get(route('research.reasoning.analysis.step', [$result, 'artifact']))->assertNotFound();
        $this->actingAs($this->other)->post(route('research.reasoning.analysis.code', $this->project), ['kind' => 'theme-tag', 'label' => 'Funding'])->assertSessionHasNoErrors();
        $this->actingAs($this->reader)->get(route('research.reasoning.analysis', $this->project))->assertOk()->assertSee('Toll income 1895-1910')->assertSee('Toll income rose after 1900.')->assertSee('Funding');
    }
}
