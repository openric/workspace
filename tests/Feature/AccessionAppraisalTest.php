<?php

// OpenRiC Workspace - the rest of accessions (roadmap phase 9d): Heratio's whole add form, appraisal with its template,
// scored criteria and the requirement before accepting, valuations and their report, containers made from the
// accession, and rights held by the accession and passed to the descriptions made from it.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\AccessionAppraisalService;
use App\Description\AccessionService;
use App\Description\AgentService;
use App\Description\RepositoryService;
use App\Models\Accession;
use App\Models\ContactPoint;
use App\Models\Container;
use App\Models\Identifier;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\Relation;
use App\Models\Repository;
use App\Models\Right;
use App\Models\User;
use App\Support\Vocab;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessionAppraisalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $editor;

    private Repository $holder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($institution);
        $accounts = app(AccountService::class);
        $this->admin = $accounts->create($institution, 'Admin', 'admin@example.org', 'correct horse battery staple');
        $accounts->grantTemplateRole($this->admin, 'administrator');
        $this->editor = $accounts->create($institution, 'Editor', 'editor@example.org', 'correct horse battery staple');
        $accounts->grantTemplateRole($this->editor, 'editor');
        $this->holder = app(RepositoryService::class)->create($institution, 'Riverside Archives', 'en', 'RA');
    }

    private function accession(string $number): Accession
    {
        return Accession::whereIn('id', Identifier::where(['subject_table' => 'accessions', 'value' => $number])->select('subject_id'))->sole();
    }

    public function test_the_add_form_takes_every_heratio_field(): void
    {
        $creator = app(AgentService::class)->create(Institution::firstOrFail(), 'person', 'Botha, Pieter', 'en');
        $this->actingAs($this->editor)->get('/accessions/new')->assertOk()->assertSee('Donor/Transferring body area')->assertSee('Primary contact information')
            ->assertSee('Administration area')->assertSee('Acquisition date')->assertSee('Botha, Pieter');
        $this->actingAs($this->editor)->post('/accessions', ['language' => 'en', 'number' => '2026-020', 'repository' => $this->holder->id, 'acquisition_type' => 'gift',
            'identifier_type' => 'previous', 'identifier_value' => 'OLD-77', 'acquisition_date' => '2026-10-01',
            'material_property' => 'creation', 'material_expressed' => 'ca. 1950-1965', 'material_start' => '1950', 'material_end' => '1965',
            'event' => ['event_type' => 'physical-transfer', 'date' => '2026-09-30', 'agent' => 'A. Botha'],
            'donor_name' => 'Botha, Anna', 'donor_type' => 'person', 'donor_contact' => ['telephone' => '021 555 0101', 'email' => 'anna@example.org', 'city' => 'Stellenbosch', 'country_code' => 'za'],
            'creators' => [$creator->id],
            'values' => ['accession-title' => 'Papers of the Botha family', 'accession-source-of-acquisition' => 'The family', 'accession-location-information' => 'Strongroom 2']])
            ->assertSessionHasNoErrors()->assertRedirect();
        $accession = $this->accession('2026-020');
        $this->assertSame(['Papers of the Botha family', 'The family', 'Strongroom 2', $this->holder->id, 'gift'],
            [$accession->described('accession-title', ['en']), $accession->described('accession-source-of-acquisition', ['en']), $accession->described('accession-location-information', ['en']),
                $accession->repository_id, $accession->acquisitionType?->notation]);
        $donor = app(AccessionService::class)->donors($accession)->sole();
        $this->assertSame('Botha, Anna', $donor->described('agent-authorised-name', ['en']));
        $this->assertSame(['021 555 0101', 'ZA', true], array_values(ContactPoint::where('subject_id', $donor->id)->sole()->only(['telephone', 'country_code', 'is_primary'])));
        $this->assertTrue(Relation::where(['source_id' => $accession->id, 'target_id' => $creator->id])->sole()->kind() === 'material-creator');
        $this->assertSame(['2026-10-01', '1950/1965'], $accession->dates()->reorder('created_at')->pluck('normalized_value')->all());
        $this->assertSame('OLD-77', Identifier::where(['subject_table' => 'accessions', 'subject_id' => $accession->id])->whereHas('identifierType', fn ($q) => $q->where('notation', 'previous'))->value('value'));
        $this->assertSame('A. Botha', $accession->events()->sole()->agent);
    }

    public function test_appraisal_from_a_template_scores_and_is_required_before_accepting_and_valuations_are_reported(): void
    {
        Institution::firstOrFail()->update(['accession_requires_appraisal' => true]);
        $this->actingAs($this->editor)->post('/accessions', ['number' => '2026-021', 'title' => 'Mission records', 'language' => 'en'])->assertRedirect();
        $accession = $this->accession('2026-021');
        foreach (['submitted', 'under-review'] as $to) {
            $this->actingAs($this->editor)->post("/accessions/{$accession->id}/intake", ['to' => $to])->assertSessionHasNoErrors();
        }
        $this->actingAs($this->editor)->get("/accessions/{$accession->id}")->assertOk()->assertSee('Before it can be accepted: no appraisal has been completed.')->assertSee('Not appraised yet.');

        $template = app(AccessionAppraisalService::class)->templates(Institution::firstOrFail())->sole();
        $this->actingAs($this->editor)->post("/accessions/{$accession->id}/appraisals", ['type' => 'archival', 'significance' => 'high', 'template' => $template->id,
            'monetary_value' => '12 500.00', 'currency' => 'zar', 'summary' => 'Strong evidential value'])->assertSessionHasNoErrors();
        $appraisal = $accession->appraisals()->sole();
        $this->assertSame([7, 'pending', '12500.00', 'ZAR'], [$appraisal->criteria()->count(), $appraisal->recommendation->notation, $appraisal->monetary_value, $appraisal->currency]);
        [$evidential, $intrinsic] = [$appraisal->criteria()->where('name', 'Evidential value')->sole(), $appraisal->criteria()->where('name', 'Intrinsic value')->sole()];
        $this->actingAs($this->editor)->post("/accessions/{$accession->id}/appraisals/{$appraisal->id}/criteria", ['action' => 'score', 'criterion' => $evidential->id, 'score' => '5'])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->post("/accessions/{$accession->id}/appraisals/{$appraisal->id}/criteria", ['action' => 'score', 'criterion' => $intrinsic->id, 'score' => '3'])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->post("/accessions/{$accession->id}/appraisals/{$appraisal->id}/criteria", ['action' => 'score', 'criterion' => $intrinsic->id, 'score' => '9'])->assertSessionHasErrors('accession');
        // (5 x 1.0 + 3 x 0.8) / 1.8
        $this->assertSame(4.11, $appraisal->fresh()->weightedScore());
        $this->actingAs($this->editor)->post("/accessions/{$accession->id}/intake", ['to' => 'accepted'])->assertSessionHasErrors('accession');

        $this->actingAs($this->editor)->post("/accessions/{$accession->id}/appraisals/{$appraisal->id}", ['type' => 'archival', 'recommendation' => 'accept', 'significance' => 'high'])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->post("/accessions/{$accession->id}/intake", ['to' => 'accepted'])->assertSessionHasNoErrors();

        $this->actingAs($this->editor)->post("/accessions/{$accession->id}/valuations", ['type' => 'initial', 'monetary_value' => '10000', 'valued_on' => '2026-01-15', 'method' => 'market'])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->post("/accessions/{$accession->id}/valuations", ['type' => 'revaluation', 'monetary_value' => '15000.50', 'valued_on' => '2026-10-01'])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->post("/accessions/{$accession->id}/valuations", ['type' => 'initial', 'monetary_value' => 'lots'])->assertSessionHasErrors('accession');
        $this->actingAs($this->editor)->get("/accessions/{$accession->id}")->assertOk()->assertSee('4.11 / 5')->assertSee('Current value')->assertSee('ZAR 15,000.50')
            ->assertSee('Valuation recorded: ZAR 15,000.50 (Revaluation)')->assertSee('Appraisal created: Archival');
        $this->actingAs($this->editor)->get('/accessions/valuation-report')->assertOk()->assertSee('Accessions valued')->assertSee('ZAR 15,000.50')->assertDontSee('ZAR 10,000.00');
    }

    public function test_containers_are_made_from_the_accession_and_its_rights_pass_to_the_descriptions_made_from_it(): void
    {
        $this->actingAs($this->editor)->post('/accessions', ['number' => '2026-022', 'title' => 'Farm papers', 'language' => 'en'])->assertRedirect();
        $accession = $this->accession('2026-022');
        $accession->update(['repository_id' => $this->holder->id]);
        $this->actingAs($this->editor)->post("/accessions/{$accession->id}/containers", ['name' => 'Box 1', 'barcode' => 'B0001', 'condition' => 'fair', 'item_count' => '40',
            'weight_kg' => '12.5', 'language' => 'en'])->assertSessionHasNoErrors();
        $box = Container::latest('created_at')->firstOrFail();
        $this->assertSame(['fair', 40, '12.50', 'active'], [$box->condition->notation, $box->item_count, $box->weight_kg, $box->status->notation]);
        $this->assertSame('storage', Relation::where(['source_id' => $accession->id, 'target_id' => $box->id])->sole()->kind());
        $this->actingAs($this->editor)->get("/accessions/{$accession->id}")->assertOk()->assertSee('Box 1')->assertSee('B0001')->assertSee('Container added: Box 1');
        $this->actingAs($this->editor)->get("/containers/{$box->id}")->assertOk()->assertSee('Fair')->assertSee('12.5 kg, 40 items');

        $this->actingAs($this->editor)->post("/accessions/{$accession->id}/rights", ['basis' => 'donor', 'access_restriction' => 'conditional', 'conditions' => 'Only with the family\'s consent',
            'inherit_to_children' => '1', 'grants' => ['publish' => ['restriction' => 'disallow']]])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->post("/accessions/{$accession->id}/rights", ['basis' => 'policy', 'inherit_to_children' => '0'])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->get("/accessions/{$accession->id}")->assertOk()->assertSee('Only with the family')->assertSee('passes to the descriptions made from it')
            ->assertSee('stays with the accession')->assertSee('Right added: Donor');

        $this->actingAs($this->editor)->post("/accessions/{$accession->id}/description", ['level' => Vocab::BASE.'level-of-description/fonds', 'language' => 'en'])
            ->assertSessionHas('status', 'Archival description created from accession. It inherited 1 right from the accession.');
        $record = RecordResource::latest('created_at')->firstOrFail();
        $copy = Right::where(['subject_table' => 'record_resources', 'subject_id' => $record->id])->sole();
        $this->assertSame(['donor', 'conditional', 'Only with the family\'s consent', ['publish' => 'disallow']],
            [$copy->basis->notation, $copy->accessRestriction->notation, $copy->conditions, $copy->grants->mapWithKeys(fn ($g) => [$g->act->notation => $g->restriction->notation])->all()]);
        $this->assertNotNull($copy->inherited_from_id);

        // With the setting off, nothing passes.
        Institution::firstOrFail()->update(['accession_rights_inheritance' => false]);
        $this->actingAs($this->editor)->post("/accessions/{$accession->id}/description", ['level' => Vocab::BASE.'level-of-description/fonds', 'language' => 'en'])
            ->assertSessionHas('status', 'Archival description created from accession.');
    }

    public function test_the_settings_keep_the_appraisal_templates(): void
    {
        $this->actingAs($this->admin)->get('/settings/accessions')->assertOk()->assertSee('Archival Appraisal')->assertSee('Give the containers of accessions barcodes');
        $this->actingAs($this->admin)->post('/settings/accessions/appraisal-templates', ['name' => 'Museum objects', 'sector' => 'museum', 'is_default' => '1',
            'criteria' => "Provenance | 1.0 | Its known history\nRarity | 0.5"])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/settings/accessions/appraisal-templates', ['name' => 'Bad', 'criteria' => 'Rarity | none'])->assertSessionHasErrors('template');
        $templates = app(AccessionAppraisalService::class)->templates(Institution::firstOrFail());
        $this->assertSame(['Museum objects' => true, 'Archival Appraisal' => false], $templates->pluck('is_default', 'name')->all());
        $this->assertSame([['name' => 'Provenance', 'weight' => 1, 'description' => 'Its known history'], ['name' => 'Rarity', 'weight' => 0.5, 'description' => null]],
            $templates->firstWhere('name', 'Museum objects')->criteria);
        $this->actingAs($this->editor)->post('/settings/accessions/appraisal-templates', ['name' => 'X', 'criteria' => 'Y'])->assertForbidden();
    }
}
