<?php

// OpenRiC Workspace - repositories and their language values (WS-001, WS-004, WS-005).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Accounts\AccountService;
use App\Description\RepositoryService;
use App\Models\Concept;
use App\Models\DescriptionValue;
use App\Models\Institution;
use App\Models\Repository;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class RepositoryTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private User $archivist;

    private RepositoryService $repositories;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        $accounts = app(AccountService::class);
        $this->repositories = app(RepositoryService::class);
        $this->institution = Institution::create(['name' => 'Riverside Parish Council Archives', 'base_iri' => 'https://archives.example.org']);
        $accounts->seedDefaultGrants($this->institution);
        $this->archivist = $accounts->create($this->institution, 'An Archivist', 'archivist@example.org', 'correct horse battery staple');
        $accounts->grantRole($this->archivist, Vocab::role('archivist'));
    }

    public function test_an_archivist_creates_a_repository_with_a_persistent_iri(): void
    {
        $this->actingAs($this->archivist)->post('/repositories', ['name' => 'Riverside Parish Council Archives', 'language' => 'en', 'identifier' => 'ZA-RPC'])
            ->assertRedirect();

        $repository = Repository::firstOrFail();
        $this->assertSame("https://archives.example.org/repository/{$repository->id}", $repository->iri);
        $this->assertSame('ZA-RPC', $repository->identifier);
        $name = $repository->descriptionValues()->firstOrFail();
        $this->assertSame('Riverside Parish Council Archives', $name->value);
        $this->assertTrue($name->is_original);
        $this->assertSame(Vocab::valueState('draft'), $name->state->iri);
        $this->actingAs($this->archivist)->get("/repositories/{$repository->id}")->assertOk()->assertSee($repository->iri);
    }

    public function test_a_translation_is_kept_beside_the_original(): void
    {
        $repository = $this->repositories->create($this->institution, 'Riverside Parish Council Archives', 'en');
        $this->actingAs($this->archivist)->post("/repositories/{$repository->id}", [
            'language' => 'af', 'values' => ['authorised-name' => 'Riverside Gemeenteraad Argief', 'history' => 'Gestig in 1902.'],
        ])->assertRedirect(route('repositories.show', $repository));

        $values = DescriptionValue::where('subject_id', $repository->id)->get()->keyBy(fn ($v) => $v->element->notation.'/'.$v->language);
        $this->assertTrue($values['authorised-name/en']->is_original);
        $this->assertFalse($values['authorised-name/af']->is_original);
        $this->assertTrue($values['history/af']->is_original);
        $this->assertSame('Riverside Parish Council Archives', $repository->fresh()->described('authorised-name', ['en']));
        $this->assertSame('Riverside Gemeenteraad Argief', $repository->fresh()->described('authorised-name', ['af', 'en']));
    }

    public function test_an_emptied_value_is_removed_but_the_last_name_is_kept(): void
    {
        $repository = $this->repositories->create($this->institution, 'Riverside Parish Council Archives', 'en');
        $this->repositories->describe($repository, ['history' => 'Founded in 1902.'], 'en');
        $this->repositories->describe($repository, ['history' => ''], 'en');
        $this->assertNull($repository->fresh()->described('history', ['en']));

        $this->actingAs($this->archivist)->post("/repositories/{$repository->id}", ['language' => 'en', 'values' => ['authorised-name' => '']])
            ->assertSessionHasErrors('values');
        $this->assertSame('Riverside Parish Council Archives', $repository->fresh()->described('authorised-name', ['en']));
    }

    public function test_a_changed_value_goes_back_to_draft_and_is_audited(): void
    {
        $repository = $this->repositories->create($this->institution, 'Riverside Parish Council Archives', 'en');
        $value = $repository->descriptionValues()->firstOrFail();
        $value->update(['state_id' => Concept::where('iri', Vocab::valueState('published'))->value('id')]);

        $this->repositories->describe($repository, ['authorised-name' => 'Riverside Parish Council Archive'], 'en', $this->archivist);

        $this->assertSame(Vocab::valueState('draft'), $value->fresh()->state->iri);
        $this->assertTrue(DB::table('audit_log')->where('subject_table', 'description_values')->where('after', 'like', '%Riverside Parish Council Archive"%')->exists());
    }

    public function test_a_language_tag_must_be_bcp47(): void
    {
        $this->actingAs($this->archivist)->post('/repositories', ['name' => 'X', 'language' => 'english'])->assertSessionHasErrors('language');
        $this->expectException(InvalidArgumentException::class);
        $this->repositories->create($this->institution, 'X', 'EN_gb');
    }

    public function test_describing_needs_the_describe_permission(): void
    {
        $plain = app(AccountService::class)->create($this->institution, 'No Role', 'norole@example.org', 'correct horse battery staple');
        $this->actingAs($plain)->get('/repositories')->assertForbidden();
        $this->actingAs($plain)->post('/repositories', ['name' => 'X', 'language' => 'en'])->assertForbidden();
    }

    public function test_another_institutions_repository_does_not_exist_here(): void
    {
        $elsewhere = Institution::create(['name' => 'Elsewhere', 'base_iri' => 'https://elsewhere.example.org']);
        $theirs = $this->repositories->create($elsewhere, 'Elsewhere Archives', 'en');

        $this->actingAs($this->archivist)->get('/repositories')->assertDontSee('Elsewhere Archives');
        $this->actingAs($this->archivist)->get("/repositories/{$theirs->id}")->assertNotFound();
        $this->actingAs($this->archivist)->post("/repositories/{$theirs->id}", ['language' => 'en', 'values' => ['history' => 'x']])->assertNotFound();
    }

    public function test_the_database_keeps_the_persistent_iri(): void
    {
        $repository = $this->repositories->create($this->institution, 'Riverside Parish Council Archives', 'en');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('cannot change');
        Repository::whereKey($repository->id)->update(['iri' => 'https://elsewhere.example.org/repository/x']);
    }

    public function test_the_database_refuses_a_state_from_the_wrong_vocabulary(): void
    {
        $repository = $this->repositories->create($this->institution, 'Riverside Parish Council Archives', 'en');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('must be a concept of');
        $repository->descriptionValues()->firstOrFail()->update(['state_id' => Concept::where('iri', Vocab::role('archivist'))->value('id')]);
    }
}
