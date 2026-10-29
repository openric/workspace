<?php

// OpenRiC Workspace - accessions (Heratio's ahg-accession-manage, roadmap phase 9a): registered with a number unique
// in the institution, described in CAAIS's sections, with donors taking part in the donor role, their confidentiality,
// CAAIS's extent, language, preservation and event rows, deaccessions, a description made from one, the CSV and CAAIS
// exports, and staff only.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\AgentService;
use App\Description\PublicationService;
use App\Description\RepositoryService;
use App\Models\Accession;
use App\Models\Identifier;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\Relation;
use App\Models\Repository;
use App\Models\User;
use App\Relations\RelationService;
use App\Support\Vocab;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessionTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://archives.example.org';

    private User $editor;

    private User $researcher;

    private Repository $holder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => self::SITE]);
        app(RoleService::class)->seedDefaults($institution);
        $accounts = app(AccountService::class);
        $this->editor = $accounts->create($institution, 'Editor', 'editor@example.org', 'correct horse battery staple');
        $accounts->grantTemplateRole($this->editor, 'editor');
        $this->researcher = $accounts->create($institution, 'Researcher', 'researcher@example.org', 'correct horse battery staple');
        $this->holder = app(RepositoryService::class)->create($institution, 'Riverside Archives', 'en', 'RA');
    }

    private function register(string $number = '2026-014', string $title = 'Papers of the Botha family'): Accession
    {
        $this->actingAs($this->editor)->post('/accessions', ['number' => $number, 'title' => $title, 'language' => 'en'])->assertRedirect();

        return Accession::whereNull('deleted_at')->whereIn('id', Identifier::where(['subject_table' => 'accessions', 'value' => $number])->select('subject_id'))->firstOrFail();
    }

    public function test_an_accession_is_registered_described_and_found(): void
    {
        $accession = $this->register();
        $this->actingAs($this->editor)->post('/accessions', ['number' => '2026-014', 'language' => 'en'])->assertSessionHasErrors('number');
        $this->actingAs($this->editor)->post("/accessions/{$accession->id}", ['language' => 'en', 'number' => '2026-014', 'repository' => $this->holder->id,
            'acquisition_type' => 'gift', 'resource_type' => 'private-transfer', 'processing_status' => 'in-progress', 'processing_priority' => 'high',
            'values' => ['accession-scope-and-content' => 'Letters and diaries, 1890-1950.', 'accession-received-extent-units' => 'Three boxes', 'accession-processing-notes' => 'Diaries need boxing.']])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->get("/accessions/{$accession->id}")->assertOk()
            ->assertSee('2026-014 Papers of the Botha family')->assertSee('Gift')->assertSee('In-Progress')->assertSee('3.3 Scope and content')->assertSee('Letters and diaries, 1890-1950.')
            ->assertSee('3 Materials section');
        $this->actingAs($this->editor)->get("/accessions/{$accession->id}/edit")->assertOk()->assertSee('6.1 Processing notes')->assertSee('Diaries need boxing.');
        $this->actingAs($this->editor)->get('/accessions?q=2026-0')->assertOk()->assertSee('Papers of the Botha family');
        $this->actingAs($this->editor)->get('/accessions?status=complete')->assertOk()->assertDontSee('Papers of the Botha family');
        $csv = $this->actingAs($this->editor)->get('/accessions/export.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('2026-014,"Papers of the Botha family",,In-Progress,High', $csv);

        // Staff only: an account with no accession permission, and the public, never reach it.
        $this->actingAs($this->researcher)->get('/accessions')->assertForbidden();
        $this->actingAs($this->researcher)->get("/accessions/{$accession->id}")->assertForbidden();
        $this->actingAs($this->editor)->get($accession->iri)->assertRedirect(route('accessions.show', $accession));
        auth()->logout();
        $this->get("/accessions/{$accession->id}")->assertRedirect();
    }

    public function test_caais_rows_donors_and_the_caais_export(): void
    {
        $accession = $this->register();
        $donor = app(AgentService::class)->create(Institution::firstOrFail(), 'person', 'Botha, Anna', 'en');
        app(RelationService::class)->create($accession, 'participation', $donor, ['role' => 'donor'], $this->editor);
        $this->actingAs($this->editor)->get('/accessions/donors')->assertOk()->assertSee('Botha, Anna')->assertSee('1 accession');

        foreach ([['extents', ['extent_type' => 'extent-received', 'quantity' => '3', 'unit' => 'boxes', 'is_estimate' => '1']],
            ['languages', ['language' => 'af', 'note' => 'with some English']], ['events', ['event_type' => 'physical-transfer', 'date' => '2026-10-01', 'agent' => 'A. Botha']],
            ['preservation', ['requirement_type' => 'conservation', 'requirement' => 'Diaries to be boxed']]] as [$kind, $row]) {
            $this->actingAs($this->editor)->post("/accessions/{$accession->id}/rows/$kind", ['row' => $row])->assertSessionHasNoErrors();
        }
        $this->actingAs($this->editor)->post("/accessions/{$accession->id}/rows/events", ['row' => ['event_type' => 'physical-transfer', 'date' => '1 October']])->assertSessionHasErrors('accession');
        $this->actingAs($this->editor)->get("/accessions/{$accession->id}")->assertOk()->assertSee('ca. 3 Boxes')->assertSee('Afrikaans, with some English')->assertSee('Physical transfer, 2026-10-01, A. Botha');

        $caais = $this->actingAs($this->editor)->get("/accessions/{$accession->id}/caais")->assertOk()->json('records.0');
        $this->assertSame('Botha, Anna', $caais['source']['source_of_material'][0]['source_name']);
        $this->assertSame('ca. 3 Boxes', $caais['materials']['extent_statement'][0]['quantity_and_unit_of_measure']);
        $this->assertSame(['3.1 - Date of material'], $caais['conformance']['missing_mandatory']);
        $this->assertSame('Created', $caais['control']['date_of_creation_or_revision'][0]['creation_or_revision_type']);

        // A confidential source is withheld from an export for outside use.
        $this->actingAs($this->editor)->post("/accessions/{$accession->id}/confidentiality", ['donor' => $donor->id, 'confidentiality' => 'anonymous'])->assertSessionHasNoErrors();
        $this->assertSame('Anonymous', $this->actingAs($this->editor)->get("/accessions/{$accession->id}/caais")->json('records.0.source.source_of_material.0.source_confidentiality'));
        $this->assertSame([], $this->actingAs($this->editor)->get("/accessions/{$accession->id}/caais?external=1")->json('records.0.source.source_of_material'));
    }

    public function test_a_description_is_made_from_it_and_it_is_deaccessioned_and_deleted(): void
    {
        $accession = $this->register();
        $accession->update(['repository_id' => $this->holder->id]);
        $this->actingAs($this->editor)->post("/accessions/{$accession->id}/description", ['level' => Vocab::BASE.'level-of-description/fonds', 'language' => 'en'])->assertRedirect();
        $record = RecordResource::where('institution_id', $accession->institution_id)->latest('created_at')->firstOrFail();
        $this->assertSame(['Papers of the Botha family', '2026-014'], [$record->described('title', ['en']), $record->identifier]);
        $this->assertSame('result', Relation::where(['source_id' => $accession->id, 'target_id' => $record->id])->sole()->kind());
        // The description shows the accession it came from to staff only.
        $this->actingAs($this->editor)->get("/records/{$record->id}")->assertOk()->assertSee('Results or resulted from 2026-014');
        app(PublicationService::class)->apply($this->holder, 'publish', null, false, $this->editor);
        app(PublicationService::class)->apply($record, 'publish', null, false, $this->editor);
        $this->actingAs($this->researcher)->get("/records/{$record->id}")->assertOk()->assertDontSee('2026-014 Papers');
        auth()->logout();
        $this->get(self::SITE."/discover/records/{$record->id}")->assertOk()->assertDontSee('2026-014 Papers');

        $this->actingAs($this->editor)->post("/accessions/{$accession->id}/deaccessions", ['identifier' => 'D-1', 'scope' => 'part', 'date' => '2026-10-20', 'reason' => 'Duplicates', 'language' => 'en'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->get("/accessions/{$accession->id}")->assertOk()->assertSee('D-1')->assertSee('Part, 2026-10-20')->assertSee('Duplicates');

        // A later accession adds to it (AtoM's accrual).
        $later = $this->register('2026-020', 'Further papers');
        app(RelationService::class)->create($later, 'accrual', $accession, [], $this->editor);
        $this->actingAs($this->editor)->get("/accessions/{$later->id}")->assertOk()->assertSee('Is an accrual to 2026-014 Papers of the Botha family');
        $this->actingAs($this->editor)->get("/accessions/{$accession->id}")->assertOk()->assertSee('Has the accrual 2026-020 Further papers');

        $this->actingAs($this->editor)->post("/accessions/{$accession->id}/delete")->assertRedirect(route('accessions.index'));
        $this->actingAs($this->editor)->get("/accessions/{$accession->id}")->assertNotFound();
        $this->register('2026-014', 'A new accession under the same number');
    }
}
