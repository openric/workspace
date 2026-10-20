<?php

// OpenRiC Workspace - create, read, update and delete on every kind of entity, in the screens.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\Relation;
use App\Models\RelationEvidence;
use App\Models\Repository;
use App\Models\User;
use App\Models\Vocabulary;
use App\Relations\RelationService;
use App\Vocabulary\ConceptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrudTest extends TestCase
{
    use RefreshDatabase;

    private const FONDS = 'https://workspace.openric.org/vocab/level-of-description/fonds';

    private const FILE = 'https://workspace.openric.org/vocab/level-of-description/file';

    private Institution $institution;

    private Repository $holder;

    private RecordResource $fonds;

    private RecordResource $file;

    private User $administrator;

    private User $contributor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        $accounts = app(AccountService::class);
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->administrator = $accounts->create($this->institution, 'An Administrator', 'admin@example.org', 'correct horse battery staple');
        $accounts->grantTemplateRole($this->administrator, 'administrator');
        $this->contributor = $accounts->create($this->institution, 'A Contributor', 'contributor@example.org', 'correct horse battery staple');
        $accounts->grantTemplateRole($this->contributor, 'contributor');
        $this->holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'ZA-RA');
        $records = app(RecordService::class);
        $this->fonds = $records->create($this->institution, self::FONDS, 'Riverside Parish Council records', 'en', null, $this->holder, 'RPC');
        $this->file = $records->create($this->institution, self::FILE, 'Minutes, 1902', 'en', $this->fonds, $this->holder, 'RPC/1');
    }

    public function test_lists_and_pages_offer_edit_and_delete_as_far_as_the_account_may(): void
    {
        $this->actingAs($this->administrator)->get('/records')->assertOk()
            ->assertSee(route('records.edit', [$this->fonds, 'language' => 'en']), false)->assertSee(route('deletion.confirm', ['records', $this->fonds->id]), false);
        $this->actingAs($this->administrator)->get('/repositories')->assertSee(route('deletion.confirm', ['repositories', $this->holder->id]), false);
        $this->actingAs($this->administrator)->get("/records/{$this->fonds->id}")->assertSee('Edit description')->assertSee(route('deletion.confirm', ['records', $this->file->id]), false)
            ->assertSee('edit or remove');
        // A contributor edits but does not delete descriptions.
        $this->actingAs($this->contributor)->get('/records')->assertSee(route('records.edit', [$this->fonds, 'language' => 'en']), false)
            ->assertDontSee(route('deletion.confirm', ['records', $this->fonds->id]), false);
        $this->actingAs($this->contributor)->get('/repositories')->assertDontSee(route('deletion.confirm', ['repositories', $this->holder->id]), false);
    }

    public function test_a_unit_is_deleted_after_its_units_with_its_relations_and_evidence(): void
    {
        $other = app(RecordService::class)->create($this->institution, self::FONDS, 'Ratepayers association records', 'en', null, $this->holder, 'RRA');
        $relations = app(RelationService::class);
        $related = $relations->create($this->file, 'associated-record', $other, [], $this->administrator);
        $proved = $relations->create($other, 'creation', $this->holder, [], $this->administrator);
        $relations->addEvidence($proved, $this->file);

        $this->actingAs($this->administrator)->get("/records/{$this->fonds->id}/delete")->assertOk()->assertSee('It has 1 unit of description inside it');
        $this->actingAs($this->administrator)->post("/records/{$this->fonds->id}/delete")->assertSessionHasErrors('delete');
        $this->actingAs($this->contributor)->post("/records/{$this->file->id}/delete")->assertForbidden();
        $this->actingAs($this->administrator)->get("/records/{$this->file->id}/delete")->assertOk()->assertSee('1 relation')->assertSee('1 use of it as evidence');
        $this->actingAs($this->administrator)->post("/records/{$this->file->id}/delete")->assertRedirect(route('records.show', $this->fonds));

        $this->assertNotNull($this->file->fresh()->deleted_at);
        $this->assertNull(Relation::find($related->id));
        $this->assertNotNull(Relation::find($proved->id), 'the relation it proved stays');
        $this->assertSame(0, RelationEvidence::count());
        $this->actingAs($this->administrator)->get("/records/{$this->file->id}")->assertNotFound();

        $this->actingAs($this->administrator)->post("/records/{$this->fonds->id}/delete")->assertRedirect(route('records.index'));
        $this->actingAs($this->administrator)->get('/records')->assertSee('Ratepayers association records')->assertDontSee(route('records.show', $this->fonds), false);
    }

    public function test_a_repository_holding_units_is_not_deleted(): void
    {
        $this->actingAs($this->administrator)->get("/repositories/{$this->holder->id}/delete")->assertOk()->assertSee('It has 2 units of description it holds');
        $this->actingAs($this->administrator)->post("/repositories/{$this->holder->id}/delete")->assertSessionHasErrors('delete');
        $empty = app(RepositoryService::class)->create($this->institution, 'Empty Archives', 'en');
        $this->actingAs($this->administrator)->post("/repositories/{$empty->id}/delete")->assertRedirect(route('repositories.index'));
        $this->assertNotNull($empty->fresh()->deleted_at);
        $this->actingAs($this->administrator)->get('/repositories')->assertSee('Deleted: Empty Archives.')->assertDontSee(route('repositories.show', $empty), false);
    }

    public function test_only_an_empty_vocabulary_is_deleted(): void
    {
        $concepts = app(ConceptService::class);
        $full = $concepts->createScheme($this->institution, 'subject', 'Subjects', 'en');
        $concepts->create($full, ['label' => 'Water', 'language' => 'en']);
        $empty = $concepts->createScheme($this->institution, 'subject', 'Spare', 'en');
        $this->actingAs($this->administrator)->get("/vocabularies/{$empty->id}")->assertSee('Delete this empty vocabulary');
        $this->actingAs($this->administrator)->get("/vocabularies/{$full->id}")->assertDontSee('Delete this empty vocabulary');
        $this->actingAs($this->administrator)->post("/vocabularies/{$full->id}/remove")->assertSessionHasErrors('vocabulary');
        $this->actingAs($this->contributor)->post("/vocabularies/{$empty->id}/remove")->assertForbidden();
        $this->actingAs($this->administrator)->post("/vocabularies/{$empty->id}/remove")->assertRedirect(route('vocabularies.index'));
        $this->assertNull(Vocabulary::find($empty->id));
        $this->assertNotNull(Vocabulary::find($full->id));
    }
}
