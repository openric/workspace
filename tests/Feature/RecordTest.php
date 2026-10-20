<?php

// OpenRiC Workspace - units of description: hierarchy, description and dates (WS-002, WS-004).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// tests/Fixtures/date-type.ttl is the published OpenRiC date-type vocabulary
// (https://openric.org/vocab/date-type.ttl, CC BY 4.0), copied at spec v0.53.0.

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\User;
use App\Support\Vocab;
use App\Vocabulary\SkosImporter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class RecordTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private const DATE = 'https://openric.org/vocab/date-type/';

    private Institution $institution;

    private Repository $repository;

    private User $archivist;

    private RecordService $records;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/date-type.ttl')));
        $accounts = app(AccountService::class);
        $this->records = app(RecordService::class);
        $this->institution = Institution::create(['name' => 'Riverside Parish Council Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->archivist = $accounts->create($this->institution, 'An Archivist', 'archivist@example.org', 'correct horse battery staple');
        $accounts->grantTemplateRole($this->archivist, 'editor');
        $this->repository = app(RepositoryService::class)->create($this->institution, 'Riverside Parish Council Archives', 'en');
    }

    private function fonds(string $title = 'Riverside Parish Council'): RecordResource
    {
        return $this->records->create($this->institution, self::LEVEL.'fonds', $title, 'en', null, $this->repository, 'ZA-RPC-1');
    }

    public function test_a_unit_gets_a_persistent_iri_and_children_inherit_the_holder(): void
    {
        $fonds = $this->fonds();
        $series = $this->records->create($this->institution, self::LEVEL.'series', 'Minutes', 'en', $fonds);
        $second = $this->records->create($this->institution, self::LEVEL.'series', 'Correspondence', 'en', $fonds);

        $this->assertSame("https://archives.example.org/record/{$fonds->id}", $fonds->iri);
        $this->assertSame($this->repository->id, $series->repository_id);
        $this->assertSame(['Minutes', 'Correspondence'], $fonds->fresh()->children->map(fn ($c) => $c->described('title', ['en']))->all());
        $this->assertSame([$fonds->id], array_map(fn ($a) => $a->id, $second->fresh()->ancestors()));
    }

    public function test_moving_keeps_the_iri(): void
    {
        $fonds = $this->fonds();
        $minutes = $this->records->create($this->institution, self::LEVEL.'series', 'Minutes', 'en', $fonds);
        $file = $this->records->create($this->institution, self::LEVEL.'file', 'Minute book 1902', 'en', $minutes);
        $iri = $file->iri;

        $this->actingAs($this->archivist)->post("/records/{$file->id}/move", ['parent' => $fonds->id])->assertRedirect(route('records.show', $file));
        $this->assertSame($fonds->id, $file->fresh()->parent_id);
        $this->assertSame($iri, $file->fresh()->iri);

        $this->records->move($file->fresh(), null);
        $this->assertNull($file->fresh()->parent_id);
    }

    public function test_the_database_refuses_a_cycle(): void
    {
        $fonds = $this->fonds();
        $series = $this->records->create($this->institution, self::LEVEL.'series', 'Minutes', 'en', $fonds);
        $file = $this->records->create($this->institution, self::LEVEL.'file', 'Minute book 1902', 'en', $series);

        $this->actingAs($this->archivist)->get("/records/{$fonds->id}/move")->assertOk()->assertDontSee('Minute book 1902');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('its own ancestor');
        $this->records->move($fonds->fresh(), $file);
    }

    public function test_siblings_are_reordered(): void
    {
        $fonds = $this->fonds();
        $a = $this->records->create($this->institution, self::LEVEL.'series', 'A', 'en', $fonds);
        $b = $this->records->create($this->institution, self::LEVEL.'series', 'B', 'en', $fonds);
        $c = $this->records->create($this->institution, self::LEVEL.'series', 'C', 'en', $fonds);

        $this->actingAs($this->archivist)->post("/records/{$c->id}/reorder", ['direction' => 'up'])->assertRedirect(route('records.show', $fonds->id));
        $this->assertSame(['A', 'C', 'B'], $fonds->fresh()->children->map(fn ($x) => $x->described('title', ['en']))->all());
        $this->records->reorder($a->fresh(), -1);
        $this->assertSame(['A', 'C', 'B'], $fonds->fresh()->children->map(fn ($x) => $x->described('title', ['en']))->all(), 'the first cannot move up');
    }

    public function test_a_unit_is_described_with_isad_g_elements_only(): void
    {
        $fonds = $this->fonds();
        $this->actingAs($this->archivist)->post("/records/{$fonds->id}", [
            'language' => 'en', 'level' => self::LEVEL.'fonds', 'identifier' => 'ZA-RPC-1',
            'values' => ['scope-and-content' => 'Minutes and letters of the parish council.', 'opening-times' => 'not an ISAD(G) element'],
        ])->assertRedirect(route('records.show', $fonds));
        $this->assertSame('Minutes and letters of the parish council.', $fonds->fresh()->described('scope-and-content', ['en']));
        $this->assertNull($fonds->fresh()->described('opening-times', ['en']), 'an ISDIAH element is ignored on a record');

        $this->expectException(InvalidArgumentException::class);
        $this->records->describe($fonds, ['opening-times' => 'x'], 'en');
    }

    public function test_the_title_is_required(): void
    {
        $fonds = $this->fonds();
        $this->actingAs($this->archivist)->post("/records/{$fonds->id}", ['language' => 'en', 'level' => self::LEVEL.'fonds', 'values' => ['title' => '']])
            ->assertSessionHasErrors('values');
        $this->assertSame('Riverside Parish Council', $fonds->fresh()->described('title', ['en']));
    }

    public function test_structured_dates(): void
    {
        $fonds = $this->fonds();
        $this->actingAs($this->archivist)->post("/records/{$fonds->id}/dates", ['date_type' => self::DATE.'creation', 'begin_date' => '1902-01-01', 'end_date' => '1950-12-31'])
            ->assertRedirect(route('records.show', $fonds));
        $date = $fonds->fresh()->dates->first();
        $this->assertSame('1902-01-01', $date->begin_date->format('Y-m-d'));
        $this->assertSame(self::DATE.'creation', $date->dateType->iri);

        $this->actingAs($this->archivist)->post("/records/{$fonds->id}/dates", ['date_type' => self::DATE.'creation', 'begin_date' => '1950-01-01', 'end_date' => '1902-01-01'])
            ->assertSessionHasErrors('end_date');
        $this->actingAs($this->archivist)->post("/records/{$fonds->id}/dates", ['date_type' => Vocab::role('archivist'), 'begin_date' => '1902-01-01'])
            ->assertSessionHasErrors('date_type');

        $this->actingAs($this->archivist)->post("/records/{$fonds->id}/dates/{$date->id}/remove")->assertRedirect(route('records.show', $fonds));
        $this->assertCount(0, $fonds->fresh()->dates);
    }

    public function test_the_database_refuses_dates_out_of_order(): void
    {
        $fonds = $this->fonds();
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('record_dates_order');
        DB::table('record_dates')->insert(['id' => (string) Str::uuid7(), 'institution_id' => $this->institution->id, 'record_resource_id' => $fonds->id,
            'date_type_id' => Concept::where('iri', self::DATE.'creation')->value('id'), 'begin_date' => '1950-01-01', 'end_date' => '1902-01-01']);
    }

    public function test_another_institutions_units_do_not_exist_here(): void
    {
        $elsewhere = Institution::create(['name' => 'Elsewhere', 'base_iri' => 'https://elsewhere.example.org']);
        $theirRepository = app(RepositoryService::class)->create($elsewhere, 'Elsewhere Archives', 'en');
        $theirs = $this->records->create($elsewhere, self::LEVEL.'fonds', 'Their fonds', 'en', null, $theirRepository);

        $this->actingAs($this->archivist)->get('/records')->assertDontSee('Their fonds');
        $this->actingAs($this->archivist)->get("/records/{$theirs->id}")->assertNotFound();
        $this->expectException(InvalidArgumentException::class);
        $this->records->create($this->institution, self::LEVEL.'fonds', 'Wrong holder', 'en', null, $theirRepository);
    }

    public function test_the_pages_render(): void
    {
        $fonds = $this->fonds();
        $this->actingAs($this->archivist)->get('/records')->assertOk()->assertSee('Riverside Parish Council');
        $this->actingAs($this->archivist)->get("/records/{$fonds->id}")->assertOk()->assertSee($fonds->iri);
        $this->actingAs($this->archivist)->get("/records/{$fonds->id}/edit?language=af")->assertOk();
        $this->actingAs($this->archivist)->get("/records/new?parent={$fonds->id}")->assertOk();
        $this->actingAs($this->archivist)->post('/records', ['title' => 'A collection', 'language' => 'en', 'level' => self::LEVEL.'fonds', 'repository' => $this->repository->id])->assertRedirect();
    }
}
