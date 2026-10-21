<?php

// OpenRiC Workspace - names and identifiers as entities (rico:Name, rico:Identifier; ADR 0006).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\IdentifierService;
use App\Description\NameService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Concept;
use App\Models\Identifier;
use App\Models\Institution;
use App\Models\Name;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\RicDate;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class NameAndIdentifierTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private Repository $repository;

    private RecordResource $fonds;

    private User $archivist;

    private NameService $names;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        $accounts = app(AccountService::class);
        $this->names = app(NameService::class);
        $this->institution = Institution::create(['name' => 'Riverside Parish Council Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->archivist = $accounts->create($this->institution, 'An Archivist', 'archivist@example.org', 'correct horse battery staple');
        $accounts->grantTemplateRole($this->archivist, 'editor');
        $this->repository = app(RepositoryService::class)->create($this->institution, 'Riverside Parish Council Archives', 'en', 'ZA-RPC');
        $this->fonds = app(RecordService::class)->create($this->institution, 'https://workspace.openric.org/vocab/level-of-description/fonds',
            'Riverside Parish Council', 'en', null, $this->repository, 'RPC');
    }

    public function test_a_title_is_a_name_entity_and_a_translation_another(): void
    {
        $title = $this->fonds->names()->sole();
        $this->assertSame('authorised', $title->nameType->notation);
        $this->assertSame("https://archives.example.org/name/{$title->id}", $title->iri);
        $this->actingAs($this->archivist)->post("/records/{$this->fonds->id}", ['language' => 'af', 'level' => 'https://workspace.openric.org/vocab/level-of-description/fonds',
            'identifier' => 'RPC', 'values' => ['title' => 'Riverside Gemeenteraad']])->assertRedirect(route('records.show', $this->fonds));
        $this->assertSame('Riverside Gemeenteraad', $this->fonds->fresh()->described('title', ['af']));
        $this->assertFalse($this->fonds->names()->where('language', 'af')->sole()->is_original);
        $this->actingAs($this->archivist)->get("/records/{$this->fonds->id}")->assertOk()->assertSee($title->iri);
    }

    public function test_the_description_form_writes_parallel_and_other_names_one_per_line(): void
    {
        $url = "/repositories/{$this->repository->id}";
        $this->actingAs($this->archivist)->post($url, ['language' => 'en', 'identifier' => 'ZA-RPC',
            'values' => ['other-name' => "RPCA\nParish Archives", 'parallel-name' => 'Riverside Gemeenteraad Argief']])->assertRedirect(route('repositories.show', $this->repository));
        $kept = $this->repository->names()->where('value', 'RPCA')->sole();
        $this->assertSame('other', $kept->nameType->notation);
        $this->assertCount(4, $this->repository->names()->get());

        $this->actingAs($this->archivist)->post($url, ['language' => 'en', 'identifier' => 'ZA-RPC', 'values' => ['other-name' => 'RPCA', 'parallel-name' => '']]);
        $this->assertSame(['Riverside Parish Council Archives', 'RPCA'], $this->repository->names()->pluck('value')->all());
        $this->assertSame($kept->iri, $this->repository->names()->where('value', 'RPCA')->sole()->iri, 'a name that stays keeps its IRI');

        $this->actingAs($this->archivist)->post($url, ['language' => 'en', 'values' => ['authorised-name' => "Two\nLines"]])->assertSessionHasErrors('values');
        $this->actingAs($this->archivist)->get("{$url}/edit?language=en")->assertOk()->assertSee('5.1.3 Parallel form of name')->assertSee('RPCA');
    }

    public function test_a_name_has_the_dates_it_was_used(): void
    {
        $url = "/repositories/{$this->repository->id}/names";
        $this->actingAs($this->archivist)->post($url, ['type' => 'other', 'value' => 'Riverside Town Archives', 'language' => 'en', 'used_from' => '1902', 'used_to' => '1965'])
            ->assertRedirect(route('repositories.show', $this->repository));
        $name = $this->repository->names()->where('value', 'Riverside Town Archives')->sole();
        $this->assertSame('1902', $name->usedDate('used-from')->normalized_value);
        $this->assertSame('1965-12-31', $name->usedDate('used-to')->latest->format('Y-m-d'));

        $this->actingAs($this->archivist)->post("$url/{$name->id}", ['type' => 'other', 'value' => 'Riverside Town Archives', 'language' => 'en', 'used_from' => '1903', 'used_to' => '']);
        $name = $name->fresh();
        $this->assertSame('1903', $name->usedDate('used-from')->normalized_value);
        $this->assertNull($name->usedDate('used-to'));
        $this->actingAs($this->archivist)->post($url, ['type' => 'other', 'value' => 'X', 'language' => 'en', 'used_from' => 'about 1902'])->assertSessionHasErrors('names');

        $this->actingAs($this->archivist)->post("$url/{$name->id}/remove")->assertRedirect(route('repositories.show', $this->repository));
        $this->assertNull(Name::find($name->id));
        $this->assertSame(0, RicDate::where('subject_table', 'names')->where('subject_id', $name->id)->count(), 'its dates go with it');
    }

    public function test_the_last_authorised_name_is_kept_and_one_per_language(): void
    {
        $title = $this->fonds->names()->sole();
        $url = "/records/{$this->fonds->id}/names";
        $this->actingAs($this->archivist)->post("$url/{$title->id}/remove")->assertSessionHasErrors('names');
        $this->actingAs($this->archivist)->post("$url/{$title->id}", ['type' => 'other', 'value' => $title->value, 'language' => 'en'])->assertSessionHasErrors('names');
        $this->actingAs($this->archivist)->post($url, ['type' => 'authorised', 'value' => 'Another title', 'language' => 'en'])->assertSessionHasErrors('names');
        $this->assertSame('authorised', $title->fresh()->nameType->notation);

        $this->actingAs($this->archivist)->post($url, ['type' => 'authorised', 'value' => 'Riverside Gemeenteraad', 'language' => 'af']);
        $this->actingAs($this->archivist)->post("$url/{$title->id}/remove")->assertRedirect(route('records.show', $this->fonds));
        $this->assertSame('Riverside Gemeenteraad', $this->fonds->fresh()->described('title', ['en']));
    }

    public function test_a_changed_name_goes_back_to_draft_and_is_audited(): void
    {
        $title = $this->fonds->names()->sole();
        $title->update(['state_id' => Concept::where('iri', Vocab::valueState('published'))->value('id')]);
        $this->names->update($title, ['type' => 'authorised', 'value' => 'Riverside Parish Council records', 'language' => 'en'], $this->archivist);
        $this->assertSame(Vocab::valueState('draft'), $title->fresh()->state->iri);
        $this->assertTrue(DB::table('audit_log')->where('subject_table', 'names')->where('subject_id', $title->id)->where('actor_id', $this->archivist->id)->exists());
    }

    public function test_the_local_identifier_is_an_entity_and_unique_for_repositories(): void
    {
        $local = $this->repository->identifiers()->sole();
        $this->assertSame('ZA-RPC', $this->repository->fresh()->identifier);
        $this->assertSame('local', $local->identifierType->notation);
        $this->assertSame("https://archives.example.org/identifier/{$local->id}", $local->iri);

        $this->actingAs($this->archivist)->post('/repositories', ['name' => 'Another', 'language' => 'en', 'identifier' => 'ZA-RPC'])->assertSessionHasErrors('identifier');
        $this->assertSame(1, Repository::count());
        $other = app(RepositoryService::class)->create($this->institution, 'Another', 'en', 'ZA-ANO');
        $this->actingAs($this->archivist)->post("/repositories/{$other->id}", ['language' => 'en', 'identifier' => 'ZA-RPC', 'values' => []])->assertSessionHasErrors('values');
        $this->assertSame('ZA-ANO', $other->fresh()->identifier);

        // Units of description at different places may share an identifier at their level.
        $series = app(RecordService::class)->create($this->institution, 'https://workspace.openric.org/vocab/level-of-description/series', 'Minutes', 'en', $this->fonds, null, 'RPC');
        $this->assertSame('RPC', $series->fresh()->identifier);

        $this->actingAs($this->archivist)->post("/records/{$this->fonds->id}", ['language' => 'en', 'level' => 'https://workspace.openric.org/vocab/level-of-description/fonds', 'identifier' => 'RPC-1', 'values' => []]);
        $this->assertSame('RPC-1', $this->fonds->fresh()->identifier);
        $this->assertSame(1, $this->fonds->identifiers()->count(), 'the local identifier changes in place');
        $this->actingAs($this->archivist)->post("/records/{$this->fonds->id}", ['language' => 'en', 'level' => 'https://workspace.openric.org/vocab/level-of-description/fonds', 'identifier' => '', 'values' => []]);
        $this->assertNull($this->fonds->fresh()->identifier);
    }

    public function test_other_identifiers_are_added_changed_and_removed(): void
    {
        $url = "/repositories/{$this->repository->id}/identifiers";
        $this->actingAs($this->archivist)->post($url, ['type' => 'isil', 'value' => 'ZA-1234'])->assertRedirect(route('repositories.show', $this->repository));
        $isil = $this->repository->identifiers()->whereHas('identifierType', fn ($q) => $q->where('iri', Vocab::IDENTIFIER_TYPES.'isil'))->sole();
        $this->actingAs($this->archivist)->post($url, ['type' => 'local', 'value' => 'ZA-OTHER'])->assertSessionHasErrors('identifiers');
        $this->actingAs($this->archivist)->post($url, ['type' => 'shelfmark', 'value' => 'X'])->assertSessionHasErrors('identifiers');

        $this->actingAs($this->archivist)->post("$url/{$isil->id}", ['type' => 'previous', 'value' => 'ZA-1234'])->assertRedirect(route('repositories.show', $this->repository));
        $this->assertSame('previous', $isil->fresh()->identifierType->notation);
        $this->actingAs($this->archivist)->get("/repositories/{$this->repository->id}")->assertSee('Previous identifier: ZA-1234')->assertSee($isil->iri);
        $this->actingAs($this->archivist)->post("$url/{$isil->id}/remove");
        $this->assertNull(Identifier::find($isil->id));
    }

    public function test_access_institution_and_the_owning_entity_are_checked(): void
    {
        $title = $this->fonds->names()->sole();
        $reader = app(AccountService::class)->create($this->institution, 'A Reader', 'reader@example.org', 'correct horse battery staple');
        $this->actingAs($reader)->post("/records/{$this->fonds->id}/names", ['type' => 'other', 'value' => 'X', 'language' => 'en'])->assertForbidden();
        $this->actingAs($reader)->post("/records/{$this->fonds->id}/identifiers", ['type' => 'other', 'value' => 'X'])->assertForbidden();
        $this->actingAs($reader)->get("/records/{$this->fonds->id}")->assertOk()->assertDontSee('Remove name');

        $this->actingAs($this->archivist)->post("/repositories/{$this->repository->id}/names/{$title->id}/remove")->assertNotFound();
        $elsewhere = Institution::create(['name' => 'Elsewhere', 'base_iri' => 'https://elsewhere.example.org']);
        $theirs = app(RepositoryService::class)->create($elsewhere, 'Elsewhere Archives', 'en');
        $this->actingAs($this->archivist)->post("/repositories/{$theirs->id}/names", ['type' => 'other', 'value' => 'X', 'language' => 'en'])->assertNotFound();
        $this->actingAs($this->archivist)->post("/repositories/{$theirs->id}/identifiers", ['type' => 'other', 'value' => 'X'])->assertNotFound();
    }

    public function test_the_database_guards_names_and_identifiers(): void
    {
        $title = $this->fonds->names()->sole();
        $local = $this->fonds->identifiers()->sole();
        $authorised = Concept::where('iri', Vocab::NAME_TYPES.'authorised')->value('id');
        $this->assertRefused(fn () => Name::whereKey($title->id)->update(['iri' => 'https://elsewhere.example.org/name/x']), 'cannot change');
        $this->assertRefused(fn () => Name::whereKey($title->id)->update(['value' => '  ']), 'names_value_not_blank');
        $this->assertRefused(fn () => Name::whereKey($title->id)->update(['name_type_id' => Concept::where('iri', Vocab::role('archivist'))->value('id')]), 'must be a concept of');
        $this->assertRefused(fn () => DB::table('names')->insert([...$this->row($title), 'value' => 'Second title']), 'only one names');
        $this->assertRefused(fn () => DB::table('identifiers')->insert([...$this->row($local), 'value' => 'Second']), 'only one identifiers');
        DB::table('names')->insert([...$this->row($title), 'value' => 'In Afrikaans', 'language' => 'af', 'name_type_id' => $authorised]);
        $this->assertSame(2, $this->fonds->names()->count());
    }

    public function test_one_transaction_names_hundreds_of_subjects_without_filling_the_lock_table(): void
    {
        // An import is one transaction. The one-per-subject check once took an advisory lock per subject, held to the end,
        // and a finding aid of about 450 units from the Archives nationales filled PostgreSQL's lock table. It locks the
        // subject's row now, which takes no slot in that table.
        $records = app(RecordService::class);
        DB::transaction(function () use ($records) {
            foreach (range(1, 300) as $n) {
                $records->create($this->institution, 'https://workspace.openric.org/vocab/level-of-description/item', "Minute $n", 'en', $this->fonds, null, "M$n");
            }
            $held = DB::selectOne("SELECT count(*) AS n FROM pg_locks WHERE pid = pg_backend_pid() AND locktype = 'advisory'")->n;
            $this->assertLessThanOrEqual(1, $held, 'only the audit chain\'s single advisory lock');
        });
        $this->assertSame(300, $this->fonds->children()->count());
        // The check still holds: a second authorised name in one language is refused.
        $title = $this->fonds->names()->sole();
        $this->assertRefused(fn () => DB::table('names')->insert([...$this->row($title), 'value' => 'Second title']), 'only one names');
    }

    public function test_the_services_refuse_bad_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(IdentifierService::class)->add($this->fonds, ['type' => 'other', 'value' => "two\nlines"]);
    }

    private function row(Name|Identifier $model): array
    {
        $row = collect($model->getAttributes())->except(['id', 'iri', 'created_at', 'updated_at'])->all();
        $id = (string) Str::uuid7();

        return [...$row, 'id' => $id, 'iri' => "https://archives.example.org/x/$id"];
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
