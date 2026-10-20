<?php

// OpenRiC Workspace - dates as entities (rico:Date; ADR 0006).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// tests/Fixtures/date-type.ttl is the published OpenRiC date-type vocabulary
// (https://openric.org/vocab/date-type.ttl, CC BY 4.0), copied at spec v0.53.0.

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\DateService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\RicDate;
use App\Models\User;
use App\Support\Vocab;
use App\Vocabulary\SkosImporter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DateTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private Repository $repository;

    private RecordResource $fonds;

    private User $archivist;

    private DateService $dates;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/date-type.ttl')));
        $accounts = app(AccountService::class);
        $this->dates = app(DateService::class);
        $this->institution = Institution::create(['name' => 'Riverside Parish Council Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->archivist = $accounts->create($this->institution, 'An Archivist', 'archivist@example.org', 'correct horse battery staple');
        $accounts->grantTemplateRole($this->archivist, 'editor');
        $this->repository = app(RepositoryService::class)->create($this->institution, 'Riverside Parish Council Archives', 'en');
        $this->fonds = app(RecordService::class)->create($this->institution, 'https://workspace.openric.org/vocab/level-of-description/fonds',
            'Riverside Parish Council', 'en', null, $this->repository);
    }

    public function test_a_record_date_is_an_entity_with_its_own_iri(): void
    {
        $this->actingAs($this->archivist)->post("/records/{$this->fonds->id}/dates", ['property' => 'creation', 'normalized' => '1902/1950', 'type' => 'creation'])
            ->assertRedirect(route('records.show', $this->fonds));
        $date = $this->fonds->fresh()->dates->sole();
        $this->assertSame('https://archives.example.org/date/'.$date->id, $date->iri);
        $this->assertSame('creation', $date->property->notation);
        $this->assertSame('1902-01-01', $date->earliest->format('Y-m-d'));
        $this->assertSame('1950-12-31', $date->latest->format('Y-m-d'));
        $this->actingAs($this->archivist)->get("/records/{$this->fonds->id}")->assertOk()->assertSee('1902/1950')->assertSee($date->iri);
    }

    public function test_a_written_date_keeps_its_language_and_a_change_is_saved(): void
    {
        $date = $this->dates->add($this->fonds, ['property' => 'accumulation', 'expressed' => 'vroeë 20ste eeu', 'language' => 'af']);
        $this->assertNull($date->earliest);
        $this->actingAs($this->archivist)->post("/records/{$this->fonds->id}/dates/{$date->id}",
            ['property' => 'accumulation', 'normalized' => '190X', 'qualifier' => 'circa', 'expressed' => 'vroeë 20ste eeu', 'language' => 'af'])
            ->assertRedirect(route('records.show', $this->fonds));
        $date->refresh();
        $this->assertSame('190X', $date->normalized_value);
        $this->assertSame('circa', $date->qualifier->notation);
        $this->assertSame('1909-12-31', $date->latest->format('Y-m-d'));
    }

    public function test_bad_dates_are_refused(): void
    {
        $url = "/records/{$this->fonds->id}/dates";
        $this->actingAs($this->archivist)->post($url, ['property' => 'creation', 'normalized' => '1950/1902'])->assertSessionHasErrors('dates');
        $this->actingAs($this->archivist)->post($url, ['property' => 'creation', 'normalized' => 'circa 1902'])->assertSessionHasErrors('dates');
        $this->actingAs($this->archivist)->post($url, ['property' => 'creation'])->assertSessionHasErrors('dates');
        $this->actingAs($this->archivist)->post($url, ['property' => 'creation', 'expressed' => 'about 1902'])->assertSessionHasErrors('dates');
        $this->actingAs($this->archivist)->post($url, ['property' => 'birth', 'normalized' => '1902'])->assertSessionHasErrors('property');
        $this->actingAs($this->archivist)->post($url, ['property' => 'creation', 'normalized' => '1902', 'qualifier' => 'sometime'])->assertSessionHasErrors('dates');
        $this->assertCount(0, $this->fonds->fresh()->dates);
    }

    public function test_repositories_have_dates_too(): void
    {
        $this->actingAs($this->archivist)->post("/repositories/{$this->repository->id}/dates", ['property' => 'existence', 'normalized' => '1965/..'])
            ->assertRedirect(route('repositories.show', $this->repository));
        $date = $this->repository->fresh()->dates->sole();
        $this->assertNull($date->latest);
        $this->actingAs($this->archivist)->post("/repositories/{$this->repository->id}/dates", ['property' => 'creation', 'normalized' => '1965'])
            ->assertSessionHasErrors('property');
        // A date of one entity cannot be changed through another.
        $this->actingAs($this->archivist)->post("/records/{$this->fonds->id}/dates/{$date->id}/remove")->assertNotFound();
    }

    public function test_removal_needs_update_and_is_audited(): void
    {
        $date = $this->dates->add($this->fonds, ['property' => 'creation', 'normalized' => '1902']);
        $reader = app(AccountService::class)->create($this->institution, 'A Reader', 'reader@example.org', 'correct horse battery staple');
        $this->actingAs($reader)->post("/records/{$this->fonds->id}/dates/{$date->id}/remove")->assertForbidden();
        $this->actingAs($reader)->get("/records/{$this->fonds->id}")->assertOk()->assertSee('1902')->assertDontSee('Remove date');

        $this->actingAs($this->archivist)->post("/records/{$this->fonds->id}/dates/{$date->id}/remove")->assertRedirect(route('records.show', $this->fonds));
        $this->assertNull(RicDate::find($date->id));
        $this->assertTrue(DB::table('audit_log')->where('subject_table', 'dates')->where('subject_id', $date->id)->whereNotNull('before')->whereNull('after')->exists());
    }

    public function test_another_institutions_entity_does_not_exist_here(): void
    {
        $elsewhere = Institution::create(['name' => 'Elsewhere', 'base_iri' => 'https://elsewhere.example.org']);
        $theirs = app(RepositoryService::class)->create($elsewhere, 'Elsewhere Archives', 'en');
        $this->actingAs($this->archivist)->post("/repositories/{$theirs->id}/dates", ['property' => 'existence', 'normalized' => '1965'])->assertNotFound();
        $this->actingAs($this->archivist)->post('/agents/'.$theirs->id.'/dates', ['property' => 'existence', 'normalized' => '1965'])->assertNotFound();
    }

    public function test_the_database_guards_dates(): void
    {
        $date = $this->dates->add($this->fonds, ['property' => 'creation', 'normalized' => '1902']);
        $this->assertRefused(fn () => RicDate::whereKey($date->id)->update(['iri' => 'https://elsewhere.example.org/date/x']));
        $this->assertRefused(fn () => RicDate::whereKey($date->id)->update(['earliest' => '1950-01-01', 'latest' => '1902-01-01']), 'dates_bounds_order');
        $this->assertRefused(fn () => RicDate::whereKey($date->id)->update(['normalized_value' => null]), 'dates_not_empty');
        $this->assertRefused(fn () => RicDate::whereKey($date->id)->update(['expressed' => 'about 1902']), 'dates_expressed_language');
        $this->assertRefused(fn () => RicDate::whereKey($date->id)->update(['property_id' => Concept::where('iri', Vocab::role('archivist'))->value('id')]));
    }

    private function assertRefused(callable $write, string $message = ''): void
    {
        try {
            DB::transaction($write);
            $this->fail('the database accepted it');
        } catch (QueryException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }
}
