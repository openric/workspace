<?php

// OpenRiC Workspace - duplicate archival descriptions (roadmap phase 22b): found by the default rules, each pair once,
// compared, dismissed, and merged, the unit kept taking the other's units, files, names, values and identifiers and the
// other's IRI answering that it was merged; a declared record is not merged away.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\InstantiationService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\DuplicateDetection;
use App\Models\Instantiation;
use App\Models\Institution;
use App\Models\Name;
use App\Models\RecordResource;
use App\Models\User;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DuplicatesTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private string $store;

    private Institution $institution;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->officer = app(AccountService::class)->create($this->institution, 'Officer', 'officer@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->officer, 'administrator');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->store)) {
            exec('rm -rf '.escapeshellarg($this->store));
        }
        parent::tearDown();
    }

    public function test_duplicates_are_found_compared_dismissed_and_merged(): void
    {
        $records = app(RecordService::class);
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $fonds = $records->create($this->institution, self::LEVEL.'fonds', 'Council records', 'en', null, $holder, 'C');
        // Entered twice: the same minute book, one with its pages inside and its scope; a third unit merely like it.
        $kept = $records->create($this->institution, self::LEVEL.'file', 'Minute book, 1902', 'en', $fonds, null, 'MB-1902');
        $twin = $records->create($this->institution, self::LEVEL.'file', 'Minute Book 1902', 'en', $fonds, null, 'mb 1902');
        $records->describe($twin, ['scope-and-content' => 'Minutes of the council.'], 'en');
        $page = $records->create($this->institution, self::LEVEL.'item', 'Page 1', 'en', $twin, null, 'P1');
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($path, "%PDF-1.4\n% minute book\n");
        app(InstantiationService::class)->upload($twin, $path, 'minutes.pdf', 'en', 'Scan of the minute book', null, 'online', $this->officer);
        $records->create($this->institution, self::LEVEL.'file', 'Rates register, 1902', 'en', $fonds, null, 'RR-1902');
        app(PublicationService::class)->apply($twin, 'publish', null, false, $this->officer);

        // The default rules find the pair once: similar titles and similar identifiers both name it.
        $this->actingAs($this->officer)->post(route('duplicates.scan'))->assertSessionHasNoErrors();
        $pair = DuplicateDetection::sole();
        $this->assertEqualsCanonicalizing([$kept->id, $twin->id], [$pair->record_a_id, $pair->record_b_id]);
        $this->actingAs($this->officer)->post(route('duplicates.scan'));
        $this->assertSame(1, DuplicateDetection::count(), 'each pair once');
        $this->actingAs($this->officer)->get(route('duplicates.index'))->assertOk()->assertSee('Minute Book 1902');
        $this->actingAs($this->officer)->get(route('duplicates.show', $pair))->assertOk()->assertSee('value.scope-and-content.en');

        // Merged: the kept unit takes the other's units, files, scope, title as another name, identifier as a previous one.
        $this->actingAs($this->officer)->post(route('duplicates.step', [$pair, 'merge']), ['kept' => $kept->id])->assertRedirect(route('records.show', $kept));
        $this->assertSame($kept->id, $page->fresh()->parent_id);
        $this->assertSame(1, Instantiation::where('record_resource_id', $kept->id)->count(), 'its scan moved');
        $this->assertSame('Minutes of the council.', $kept->fresh()->described('scope-and-content', ['en']));
        $this->assertTrue(Name::where(['subject_id' => $kept->id, 'value' => 'Minute Book 1902'])->whereHas('nameType', fn ($q) => $q->where('notation', 'other'))->exists());
        $this->assertContains('mb 1902', $kept->fresh()->identifiers->map->value->all());
        $twin = $twin->fresh();
        $this->assertSame([$kept->id, true], [$twin->merged_into_id, $twin->deleted_at !== null]);
        $this->assertSame('merged', $pair->fresh()->status->notation);
        // The public asks for the merged unit's IRI and is told it is gone, and what took its place.
        $this->get('https://archives.example.org/api/ric/v1/records/'.$twin->id)->assertStatus(410)->assertSee($kept->iri);
        $this->actingAs($this->officer)->post(route('duplicates.step', [$pair, 'merge']), ['kept' => $kept->id])->assertSessionHasErrors('duplicates');
    }

    public function test_a_pair_dismissed_stays_dismissed(): void
    {
        $records = app(RecordService::class);
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $fonds = $records->create($this->institution, self::LEVEL.'fonds', 'Council records', 'en', null, $holder, 'C');
        $records->create($this->institution, self::LEVEL.'file', 'Letters received, 1902', 'en', $fonds, null, 'L-1');
        $records->create($this->institution, self::LEVEL.'file', 'Letters received, 1903', 'en', $fonds, null, 'L-2');
        $this->actingAs($this->officer)->post(route('duplicates.scan'));
        $pair = DuplicateDetection::sole();
        $this->assertSame('title-similarity', $pair->ruleType->notation);
        $this->actingAs($this->officer)->post(route('duplicates.step', [$pair, 'dismiss']), ['notes' => 'Different years'])->assertRedirect(route('duplicates.index'));
        $this->actingAs($this->officer)->post(route('duplicates.scan'));
        $this->assertSame(['dismissed', 'Different years', 1], [$pair->fresh()->status->notation, $pair->fresh()->review_notes, DuplicateDetection::count()]);
        $this->assertSame(2, RecordResource::where('parent_id', $fonds->id)->whereNull('deleted_at')->count());
    }
}
