<?php

// OpenRiC Workspace - relation types as rows: the bundled types read from relation_types with their Afrikaans, and an
// institution's own type, as AtoM's and Heratio's relation type taxonomies allow, narrowing a bundled one with its own
// wording and converse term, connecting, exporting and counting as that type.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\AgentService;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\Relation;
use App\Models\User;
use App\Relations\RelationService;
use App\Relations\RelationTypes;
use App\Vocabulary\ConceptService;
use App\Vocabulary\LocalSkos;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class RelationTypeTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://archives.example.org';

    private Institution $institution;

    private User $editor;

    private Concept $godparent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => self::SITE]);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->editor = app(AccountService::class)->create($this->institution, 'Editor', 'editor@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->editor, 'editor');
        $types = app(ConceptService::class)->schemeOfKind($this->institution, 'relation-type');
        $this->godparent = app(ConceptService::class)->create($types, ['label' => 'Godparent', 'language' => 'en']);
    }

    public function test_the_bundled_types_are_rows_with_their_afrikaans(): void
    {
        $types = RelationTypes::all(null);
        $this->assertCount(44, $types);
        $this->assertSame(['associated-record', 'genetic', 'sequential'], array_slice(array_keys($types), 0, 3));
        $this->assertSame([['record', 'agent']], $types['creation']['pairs']);
        $this->assertTrue($types['creation']['role']);
        $this->assertSame(['subject'], $types['subject']['schemes']);
        $this->assertSame('rico:hasOrHadSomeMembersWithDocumentaryFormType', $types['documentary-form']['record_set_term']);
        $this->assertSame(['forward' => 'was created by', 'inverse' => 'created'], $types['creation']['phrases']['en']);

        $johanna = app(AgentService::class)->create($this->institution, 'person', 'Botha, Johanna', 'en');
        $pieter = app(AgentService::class)->create($this->institution, 'person', 'Botha, Pieter', 'en');
        app(RelationService::class)->create($johanna, 'spouse', $pieter, [], $this->editor);
        $this->actingAs($this->editor)->get("/agents/{$johanna->id}?lang=af")->assertOk()->assertSee(ucfirst($types['spouse']['phrases']['af']['forward']).' Botha, Pieter');
    }

    public function test_an_institution_adds_a_type_narrowing_a_bundled_one(): void
    {
        $this->actingAs($this->editor)->get("/concepts/{$this->godparent->id}")->assertOk()->assertSee('Not a relation type yet');
        $this->actingAs($this->editor)->post("/concepts/{$this->godparent->id}/relation-type", ['base' => 'storage', 'language' => 'en', 'forward' => 'keeps'])
            ->assertSessionHasErrors('concept');
        $this->actingAs($this->editor)->post("/concepts/{$this->godparent->id}/relation-type", ['base' => 'guardianship', 'language' => 'en', 'forward' => 'is the godparent of'])
            ->assertSessionHasErrors('concept');
        $this->actingAs($this->editor)->post("/concepts/{$this->godparent->id}/relation-type",
            ['base' => 'guardianship', 'language' => 'en', 'forward' => 'is the godparent of', 'inverse' => 'is the godchild of'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Relation type saved.');
        $this->actingAs($this->editor)->post("/concepts/{$this->godparent->id}/relation-type",
            ['base' => 'guardianship', 'language' => 'af', 'forward' => 'is die peetouer van', 'inverse' => 'is die peetkind van'])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->get("/concepts/{$this->godparent->id}")->assertOk()->assertSee('is the godchild of')->assertSee('is die peetkind van');

        // It connects what guardianship connects, from the relation form, and reads in its own words.
        $anna = app(AgentService::class)->create($this->institution, 'person', 'Venter, Anna', 'en');
        $koos = app(AgentService::class)->create($this->institution, 'person', 'Venter, Koos', 'en');
        $this->actingAs($this->editor)->get("/agents/{$anna->id}/relations/new")->assertOk()->assertSee('is the godparent of')->assertSee('is the godchild of');
        $this->actingAs($this->editor)->post("/agents/{$anna->id}/relations", ['relate' => $this->godparent->id.'|source', 'other' => 'agent:'.$koos->id])->assertRedirect();
        $relation = Relation::sole();
        $this->assertSame([$this->godparent->id, 'guardianship'], [$relation->relation_type_id, $relation->kind()]);
        $this->assertSame(Concept::where('notation', 'guardianship')->with('labels')->sole()->ricTerm(), $relation->ricTerm());
        $this->actingAs($this->editor)->get("/agents/{$koos->id}")->assertOk()->assertSee('Is the godchild of Venter, Anna');
        $this->actingAs($this->editor)->get("/agents/{$koos->id}?lang=af")->assertOk()->assertSee('Is die peetkind van');

        // The bundled type it narrows is kept once relations are of it, and its vocabulary is exported narrowing it.
        $this->actingAs($this->editor)->post("/concepts/{$this->godparent->id}/relation-type", ['base' => 'fostering', 'language' => 'en', 'forward' => 'fostered', 'inverse' => 'was fostered by'])
            ->assertSessionHasErrors('concept');
        $this->assertStringContainsString('broadMatch', app(LocalSkos::class)->export($this->godparent->vocabulary, 'turtle'));
    }

    public function test_a_symmetric_type_reads_the_same_both_ways_and_stays_with_its_institution(): void
    {
        app(RelationTypes::class)->define($this->godparent, 'knowing', 'en', 'was a fellow pupil of', 'ignored');
        $this->assertSame(['forward' => 'was a fellow pupil of', 'inverse' => 'was a fellow pupil of'], RelationTypes::get($this->godparent->id, $this->institution->id)['phrases']['en']);

        // Another institution neither sees it nor relates with it.
        $other = Institution::create(['name' => 'Hillside Archives', 'base_iri' => 'https://hillside.example.org']);
        $this->assertNull(RelationTypes::get($this->godparent->id, $other->id));
        $a = app(AgentService::class)->create($other, 'person', 'Smit, Ben', 'en');
        $b = app(AgentService::class)->create($other, 'person', 'Smit, Ria', 'en');
        $this->expectException(InvalidArgumentException::class);
        app(RelationService::class)->create($a, $this->godparent->id, $b, []);
    }

    public function test_the_database_refuses_a_relation_of_another_institutions_type(): void
    {
        app(RelationTypes::class)->define($this->godparent, 'knowing', 'en', 'was a fellow pupil of', null);
        $other = Institution::create(['name' => 'Hillside Archives', 'base_iri' => 'https://hillside.example.org']);
        $relation = app(RelationService::class)->create(app(AgentService::class)->create($other, 'person', 'Smit, Ben', 'en'), 'knowing',
            app(AgentService::class)->create($other, 'person', 'Smit, Ria', 'en'), [], null, ['origin' => 'import', 'label' => 'Hillside import']);
        $this->expectException(QueryException::class);
        $relation->update(['relation_type_id' => $this->godparent->id]);
    }
}
