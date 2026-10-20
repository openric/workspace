<?php

// OpenRiC Workspace - reified relations, their evidence and assertions (ADR 0006, WS-006).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// tests/Fixtures/role-type.ttl is the published OpenRiC role-type vocabulary
// (https://openric.org/vocab/role-type.ttl, CC BY 4.0).

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\Relation;
use App\Models\RelationAssertion;
use App\Models\RelationEvidence;
use App\Models\Repository;
use App\Models\RicDate;
use App\Models\User;
use App\Relations\RelationService;
use App\Vocabulary\SkosImporter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class RelationTest extends TestCase
{
    use RefreshDatabase;

    private const FONDS = 'https://workspace.openric.org/vocab/level-of-description/fonds';

    private Institution $institution;

    private Repository $holder;

    private Repository $council;

    private RecordResource $fonds;

    private RecordResource $minutes;

    private User $archivist;

    private RelationService $relations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $accounts = app(AccountService::class);
        $this->relations = app(RelationService::class);
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->archivist = $accounts->create($this->institution, 'An Archivist', 'archivist@example.org', 'correct horse battery staple');
        $accounts->grantTemplateRole($this->archivist, 'editor');
        $repositories = app(RepositoryService::class);
        $this->holder = $repositories->create($this->institution, 'Riverside Archives', 'en', 'ZA-RA');
        $this->council = $repositories->create($this->institution, 'Riverside Parish Council', 'en', 'ZA-RPC');
        $records = app(RecordService::class);
        $this->fonds = $records->create($this->institution, self::FONDS, 'Riverside Parish Council records', 'en', null, $this->holder, 'RPC');
        $this->minutes = $records->create($this->institution, self::FONDS, 'Minutes of the Riverside Ratepayers', 'en', null, $this->holder, 'RRA');
    }

    public function test_a_unit_is_related_to_its_creator_with_a_role_and_dates(): void
    {
        $this->actingAs($this->archivist)->get("/records/{$this->fonds->id}/relations/new?relate=creation|source&q=parish")
            ->assertOk()->assertSee('Riverside Parish Council')->assertDontSee('Riverside Archives (ZA-RA)');
        $this->actingAs($this->archivist)->post("/records/{$this->fonds->id}/relations", [
            'relate' => 'creation|source', 'other' => 'repository:'.$this->council->id, 'role' => 'editor', 'from' => '1902', 'to' => '1950',
            'certainty' => 'certain', 'basis' => 'Minute book 1, title page.', 'basis_language' => 'en',
        ])->assertRedirect(route('records.show', $this->fonds));

        $relation = Relation::sole();
        $this->assertSame("https://archives.example.org/relation/{$relation->id}", $relation->iri);
        $this->assertSame(['record_resources', $this->fonds->id, 'repositories', $this->council->id],
            [$relation->source_table, $relation->source_id, $relation->target_table, $relation->target_id], 'in the direction of rico:hasCreator');
        $this->assertSame('editor', $relation->role->notation);
        $this->assertSame('1950-12-31', $relation->datedAs('end')->latest->format('Y-m-d'));
        $assertion = $relation->assertions->sole();
        $this->assertSame([$this->archivist->id, 'accepted', 'account'], [$assertion->user_id, $assertion->status->notation, $assertion->origin->notation]);
        $this->assertSame('accepted', $relation->standing());

        $this->actingAs($this->archivist)->get("/records/{$this->fonds->id}")->assertSee('Was created by Riverside Parish Council, as Editor, 1902 to 1950 (accepted)')->assertSee($relation->iri);
        $this->actingAs($this->archivist)->get("/repositories/{$this->council->id}")->assertSee('Created Riverside Parish Council records');
    }

    public function test_a_relation_made_from_either_end_keeps_its_rico_direction(): void
    {
        $this->actingAs($this->archivist)->post("/repositories/{$this->holder->id}/relations", ['relate' => 'holding|source', 'other' => 'record:'.$this->minutes->id, 'state' => 'past']);
        $this->actingAs($this->archivist)->post("/records/{$this->fonds->id}/relations", ['relate' => 'holding|target', 'other' => 'repository:'.$this->council->id]);
        foreach (Relation::all() as $relation) {
            $this->assertSame(['repositories', 'record_resources'], [$relation->source_table, $relation->target_table]);
        }
        $this->actingAs($this->archivist)->get("/records/{$this->minutes->id}")->assertSee('Is or was held by Riverside Archives');
    }

    public function test_only_the_kinds_a_type_connects_and_its_fields_are_accepted(): void
    {
        $url = "/records/{$this->fonds->id}/relations";
        $this->actingAs($this->archivist)->post($url, ['relate' => 'creation|source', 'other' => 'record:'.$this->minutes->id])->assertStatus(422);
        $this->actingAs($this->archivist)->post($url, ['relate' => 'agent-hierarchical|source', 'other' => 'repository:'.$this->council->id])->assertStatus(422);
        $this->actingAs($this->archivist)->post($url, ['relate' => 'associated-record|source', 'other' => 'record:'.$this->minutes->id, 'role' => 'editor'])->assertSessionHasErrors('relations');
        $this->actingAs($this->archivist)->post($url, ['relate' => 'associated-record|source', 'other' => 'record:'.$this->minutes->id, 'description' => 'Same clerk.'])->assertSessionHasErrors('relations');
        $this->actingAs($this->archivist)->post($url, ['relate' => 'associated-record|source', 'other' => 'record:'.$this->minutes->id, 'from' => 'about 1902'])->assertSessionHasErrors('relations');
        $this->assertSame(0, Relation::count());
        $this->expectException(InvalidArgumentException::class);
        $this->relations->create($this->fonds, 'creation', $this->minutes, []);
    }

    public function test_a_model_proposes_and_a_person_reviews(): void
    {
        $relation = $this->relations->create($this->fonds, 'associated-record', $this->minutes, [], null,
            ['origin' => 'model', 'label' => 'suggester 0.1', 'basis' => 'Both name the same clerk.', 'basis_language' => 'en']);
        $proposal = $relation->assertions()->sole();
        $this->assertSame(['proposed', 'suggester 0.1', null], [$proposal->status->notation, $proposal->origin_label, $proposal->user_id]);
        $this->assertSame('proposed', $relation->fresh()->standing());

        $reader = app(AccountService::class)->create($this->institution, 'A Reader', 'reader@example.org', 'correct horse battery staple');
        $this->actingAs($reader)->get("/records/{$this->fonds->id}")->assertOk()->assertDontSee('Minutes of the Riverside Ratepayers');
        $this->actingAs($reader)->post("/records/{$this->fonds->id}/relations/{$relation->id}/assertions/{$proposal->id}/review", ['decision' => 'accept'])->assertForbidden();

        $this->actingAs($this->archivist)->get("/records/{$this->fonds->id}")->assertSee('proposed, awaiting review');
        $this->actingAs($this->archivist)->post("/records/{$this->fonds->id}/relations/{$relation->id}/assertions/{$proposal->id}/review", ['decision' => 'accept'])
            ->assertRedirect(route('records.show', $this->fonds));
        $this->assertSame([$this->archivist->id, 'accepted'], [$proposal->fresh()->reviewed_by_id, $proposal->fresh()->status->notation]);
        $this->actingAs($this->archivist)->post("/records/{$this->fonds->id}/relations/{$relation->id}/assertions/{$proposal->id}/review", ['decision' => 'reject'])
            ->assertSessionHasErrors('relations');
        $this->actingAs($reader)->get("/records/{$this->fonds->id}")->assertSee('Is associated with Minutes of the Riverside Ratepayers (accepted)');
    }

    public function test_competing_assertions_coexist_and_superseding_keeps_the_old(): void
    {
        $relation = $this->relations->create($this->fonds, 'creation', $this->council, [], $this->archivist, ['basis' => 'Title page.', 'basis_language' => 'en']);
        $first = $relation->assertions()->sole();
        $colleague = app(AccountService::class)->create($this->institution, 'A Colleague', 'colleague@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($colleague, 'editor');

        $this->actingAs($colleague)->post("/records/{$this->fonds->id}/relations/{$relation->id}/assertions", ['holds' => 'no', 'basis' => 'The council was formed in 1905.', 'basis_language' => 'en']);
        $this->assertSame('disputed', $relation->fresh()->standing());

        $this->actingAs($this->archivist)->post("/records/{$this->fonds->id}/relations/{$relation->id}/assertions", ['holds' => 'no', 'supersedes' => $first->id]);
        $relation = $relation->fresh();
        $this->assertSame('rejected', $relation->standing());
        $this->assertCount(3, $relation->assertions);
        $this->assertNotNull(RelationAssertion::find($first->id), 'the superseded assertion is kept');
        $this->actingAs($this->archivist)->post("/records/{$this->fonds->id}/relations/{$relation->id}/assertions", ['holds' => 'yes', 'supersedes' => $first->id])->assertSessionHasErrors('relations');
        $this->actingAs($this->archivist)->get("/records/{$this->fonds->id}")->assertSee('superseded')->assertSee('The council was formed in 1905.');
    }

    public function test_evidence_is_added_and_removed(): void
    {
        $relation = $this->relations->create($this->fonds, 'creation', $this->council, [], $this->archivist);
        $base = "/records/{$this->fonds->id}/relations/{$relation->id}/evidence";
        $this->actingAs($this->archivist)->get("$base?q=ratepayers")->assertOk()->assertSee('Minutes of the Riverside Ratepayers');
        $this->actingAs($this->archivist)->post($base, ['record' => $this->minutes->id])->assertRedirect(route('records.show', $this->fonds));
        $this->actingAs($this->archivist)->post($base, ['record' => $this->minutes->id])->assertSessionHasErrors('relations');
        $evidence = RelationEvidence::sole();
        $this->actingAs($this->archivist)->get("/repositories/{$this->council->id}")->assertSee('Minutes of the Riverside Ratepayers');
        $this->actingAs($this->archivist)->post("$base/{$evidence->id}/remove")->assertRedirect(route('records.show', $this->fonds));
        $this->assertSame(0, RelationEvidence::count());
    }

    public function test_a_relation_made_in_error_is_removed_with_everything_and_audited(): void
    {
        $relation = $this->relations->create($this->fonds, 'creation', $this->council, ['from' => '1902'], $this->archivist);
        $first = $relation->assertions()->sole();
        $this->relations->assert($relation, $this->archivist, true, null, null, $first);
        $this->relations->addEvidence($relation, $this->minutes, $this->archivist);
        $this->actingAs($this->archivist)->post("/repositories/{$this->council->id}/relations/{$relation->id}/remove")->assertRedirect(route('repositories.show', $this->council));
        $this->assertSame([0, 0, 0, 0], [Relation::count(), RelationAssertion::count(), RelationEvidence::count(), RicDate::where('subject_table', 'relations')->count()]);
        $this->assertSame(2, DB::table('audit_log')->where('subject_table', 'relation_assertions')->whereNull('after')->count());
    }

    public function test_access_institution_and_the_ends_of_the_relation_are_checked(): void
    {
        $relation = $this->relations->create($this->fonds, 'creation', $this->council, [], $this->archivist);
        $reader = app(AccountService::class)->create($this->institution, 'A Reader', 'reader@example.org', 'correct horse battery staple');
        $this->actingAs($reader)->post("/records/{$this->fonds->id}/relations", ['relate' => 'creation|source', 'other' => 'repository:'.$this->holder->id])->assertForbidden();
        $this->actingAs($reader)->get("/records/{$this->fonds->id}")->assertDontSee('Add a relation')->assertDontSee('Remove relation');
        $this->actingAs($this->archivist)->post("/records/{$this->minutes->id}/relations/{$relation->id}/remove")->assertNotFound();

        $elsewhere = Institution::create(['name' => 'Elsewhere', 'base_iri' => 'https://elsewhere.example.org']);
        $theirs = app(RepositoryService::class)->create($elsewhere, 'Elsewhere Archives', 'en');
        $this->actingAs($this->archivist)->post("/records/{$this->fonds->id}/relations", ['relate' => 'creation|source', 'other' => 'repository:'.$theirs->id])->assertNotFound();
        $this->actingAs($this->archivist)->get("/records/{$this->fonds->id}/relations/new?relate=creation|source&q=elsewhere")->assertDontSee('Elsewhere Archives');
        $this->expectException(InvalidArgumentException::class);
        $this->relations->create($this->fonds, 'creation', $theirs, []);
    }

    public function test_the_database_guards_relations_and_assertions(): void
    {
        $relation = $this->relations->create($this->fonds, 'creation', $this->council, [], $this->archivist);
        $other = $this->relations->create($this->minutes, 'creation', $this->council, [], $this->archivist);
        $assertion = $relation->assertions()->sole();
        $elsewhere = Institution::create(['name' => 'Elsewhere', 'base_iri' => 'https://elsewhere.example.org']);
        $theirs = app(RepositoryService::class)->create($elsewhere, 'Elsewhere Archives', 'en');

        $this->assertRefused(fn () => Relation::whereKey($relation->id)->update(['target_table' => 'record_resources', 'target_id' => $this->fonds->id]), 'relations_not_reflexive');
        $this->assertRefused(fn () => Relation::whereKey($relation->id)->update(['target_id' => $theirs->id]), 'must be an entity of its institution');
        $this->assertRefused(fn () => Relation::whereKey($relation->id)->update(['target_table' => 'users']), 'relations_entity_tables');
        $this->assertRefused(fn () => Relation::whereKey($relation->id)->update(['iri' => 'https://elsewhere.example.org/relation/x']), 'cannot change');
        $this->assertRefused(fn () => RelationAssertion::whereKey($assertion->id)->update(['basis' => 'Rewritten.', 'basis_language' => 'en']), 'what was asserted cannot change');
        $this->assertRefused(fn () => DB::table('relation_assertions')->insert([
            ...collect($assertion->getAttributes())->except(['id', 'iri'])->all(), 'id' => $id = (string) Str::uuid7(), 'iri' => "https://archives.example.org/assertion/$id",
            'relation_id' => $other->id, 'supersedes_id' => $assertion->id]), 'same relation');
        $this->assertRefused(fn () => DB::table('relation_evidence')->insert(['id' => (string) Str::uuid7(), 'institution_id' => $elsewhere->id,
            'relation_id' => $relation->id, 'record_resource_id' => $this->minutes->id]), 'evidence must be');
    }

    private function assertRefused(callable $write, string $message): void
    {
        try {
            DB::transaction($write);
            $this->fail('the database accepted it');
        } catch (QueryException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }
}
