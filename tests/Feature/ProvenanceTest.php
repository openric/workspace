<?php

// OpenRiC Workspace - the provenance and custody history of a unit of description (roadmap phase 9e): phase 9 end to
// end, a deposit registered under its agreement, the fonds made from it and the custody history it shows; the chain,
// its order and its IRIs; the overview, documents and CSV; and what the public sees and the RiC-O export says.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\AgentService;
use App\Description\AgreementService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Accession;
use App\Models\Identifier;
use App\Models\Institution;
use App\Models\ProvenanceEntry;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\User;
use App\Relations\RelationService;
use App\Support\Vocab;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ProvenanceTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://archives.example.org';

    private User $editor;

    private User $researcher;

    private Repository $holder;

    private string $store;

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
        $this->store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->store)) {
            exec('rm -rf '.escapeshellarg($this->store));
        }
        parent::tearDown();
    }

    public function test_phase_nine_end_to_end_a_deposit_under_its_agreement_becomes_a_fonds_that_shows_its_custody_history(): void
    {
        // A deposit registered with its donor, from Heratio's add form.
        $this->actingAs($this->editor)->post('/accessions', ['language' => 'en', 'number' => '2026-030', 'repository' => $this->holder->id, 'acquisition_type' => 'gift',
            'acquisition_date' => '2026-10-01', 'donor_name' => 'Botha, Anna', 'donor_type' => 'person', 'values' => ['accession-title' => 'Papers of the Botha family']])
            ->assertSessionHasNoErrors();
        $accession = Accession::whereIn('id', Identifier::where(['subject_table' => 'accessions', 'value' => '2026-030'])->select('subject_id'))->sole();
        // Under its agreement.
        $agreement = app(AgreementService::class)->create(Institution::firstOrFail(), 'DOG-2026-001', 'deed-of-gift', 'Deed of gift, Botha family', 'en', $this->editor);
        app(RelationService::class)->create($agreement, 'documentation', $accession, [], $this->editor);
        // The fonds it became.
        $this->actingAs($this->editor)->post("/accessions/{$accession->id}/description", ['level' => Vocab::BASE.'level-of-description/fonds', 'language' => 'en'])->assertRedirect();
        $fonds = RecordResource::latest('created_at')->firstOrFail();

        $steps = $fonds->provenanceEntries()->with('transferType')->get();
        $this->assertSame([['Botha, Anna', 'donation', null, '2026-10-01'], ['Riverside Archives', 'accessioning', '2026-10-01', null]],
            $steps->map(fn ($s) => [$s->owner_name, $s->transferType->notation, $s->start_date, $s->end_date])->all());
        $this->assertSame(['gift', 'permanent', 'owned'], [$fonds->provenanceOverview->acquisitionType->notation, $fonds->provenanceOverview->custodyType->notation,
            $fonds->provenanceOverview->currentStatus->notation]);
        $this->actingAs($this->editor)->get("/records/{$fonds->id}")->assertOk()->assertSee('Provenance and custody history')
            ->assertSeeInOrder(['Botha, Anna', 'Donation', 'until 2026-10-01', 'Riverside Archives', 'Accessioning', 'from 2026-10-01'])->assertSee('Accession 2026-030');
        $this->actingAs($this->editor)->get("/accessions/{$accession->id}")->assertOk()->assertSee('DOG-2026-001')->assertSee('Papers of the Botha family');

        // Published, the public sees the chain, but not the donor, whose authority record is not published, nor the accession.
        foreach ([$this->holder, $fonds] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->editor);
        }
        auth()->logout();
        $this->get(self::SITE."/discover/records/{$fonds->id}")->assertOk()->assertSee('Provenance')->assertSee('Private owner')->assertSee('Accessioning')
            ->assertDontSee('Botha, Anna')->assertDontSee('Accession 2026-030');
        $json = $this->getJson(self::SITE."/api/ric/v1/records/{$fonds->id}")->assertOk()->json();
        $this->assertSame(['rico:Activity', 'rico:Activity'], array_column($json['rico:isOrWasAffectedBy'], '@type'));
        $this->assertSame($steps[1]->iri, $json['rico:isOrWasAffectedBy'][1]['@id']);
        $this->assertSame(['@type' => 'rico:Agent'], $json['rico:isOrWasAffectedBy'][0]['rico:hasOrHadParticipant']);
        $this->assertSame(['@id' => $steps[1]->iri.'#start', '@type' => 'rico:Date', 'rico:normalizedDateValue' => '2026-10-01'], $json['rico:isOrWasAffectedBy'][1]['rico:hasBeginningDate']);
        $this->assertSame(Vocab::BASE.'provenance-transfer-type/accessioning', $json['rico:isOrWasAffectedBy'][1]['rico:hasActivityType']['@id']);
    }

    public function test_the_chain_is_edited_reordered_and_kept_with_its_iris(): void
    {
        $record = app(RecordService::class)->create(Institution::firstOrFail(), Vocab::BASE.'level-of-description/item', 'Portrait of a lady', 'en', null, $this->holder, 'P1');
        $dealer = app(AgentService::class)->create(Institution::firstOrFail(), 'corporate-body', 'Smith and Sons, dealers', 'en');
        $this->actingAs($this->editor)->post("/records/{$record->id}/provenance/steps", ['owner_name' => 'Van der Merwe family', 'owner_type' => 'family', 'transfer_type' => 'inheritance',
            'start_qualifier' => 'circa', 'start_date' => '1890', 'end_date' => '1938', 'certainty' => 'probable'])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->post("/records/{$record->id}/provenance/steps", ['owner' => $dealer->id, 'transfer_type' => 'sale', 'start_date' => '1938-05',
            'sale_price' => '1 200', 'sale_currency' => 'gbp', 'auction_house' => 'Christie\'s', 'auction_lot' => '42'])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->post("/records/{$record->id}/provenance/steps", ['owner_name' => 'X', 'start_date' => 'around 1900'])->assertSessionHasErrors('provenance');
        $this->actingAs($this->editor)->post("/records/{$record->id}/provenance/steps", ['owner_name' => 'Unknown hands', 'is_gap' => '1', 'gap_explanation' => 'Not traced 1945 to 1960'])->assertSessionHasNoErrors();
        [$family, $sale, $gap] = $record->provenanceEntries()->get()->all();
        $this->assertSame(['Smith and Sons, dealers', '1200.00', 'GBP', 'unknown'], [$sale->owner_name, $sale->sale_price, $sale->sale_currency, $gap->transferType->notation]);

        $this->actingAs($this->editor)->post("/records/{$record->id}/provenance/steps/{$gap->id}/move", ['direction' => 'earlier'])->assertRedirect();
        $this->assertSame(['Van der Merwe family', 'Unknown hands', 'Smith and Sons, dealers'], $record->provenanceEntries()->pluck('owner_name')->all());
        $this->actingAs($this->editor)->post("/records/{$record->id}/provenance/steps/{$family->id}/remove")->assertRedirect();
        $this->assertSame([[1, 'Unknown hands'], [2, 'Smith and Sons, dealers']], $record->provenanceEntries()->get()->map(fn ($s) => [$s->position, $s->owner_name])->all());
        // Removed, it is kept, and its IRI still resolves, to the record's provenance page.
        $this->assertNotNull(ProvenanceEntry::find($family->id)->deleted_at);
        $this->actingAs($this->editor)->get(parse_url($family->iri, PHP_URL_PATH))->assertRedirect(route('records.provenance', $record).'#step-'.$family->id);

        $this->actingAs($this->editor)->get("/records/{$record->id}/provenance")->assertOk()->assertSee('Ownership History')->assertSee('GBP 1,200.00')
            ->assertSee("Christie's, lot 42")->assertSee('Gap in the provenance')->assertSee('Not traced 1945 to 1960');
        $csv = $this->actingAs($this->editor)->get("/records/{$record->id}/provenance/export.csv")->assertOk()->streamedContent();
        $this->assertStringContainsString('#,Owner,"Owner Type",Location,TGN,"Start Date","End Date",Transfer,Certainty', $csv);
        $this->assertStringContainsString('2,"Smith and Sons, dealers",,,,1938-05,,Sale,,1200.00,GBP,Christie\'s,42,,,No', $csv);
    }

    public function test_the_overview_documents_and_who_may_see_them(): void
    {
        $record = app(RecordService::class)->create(Institution::firstOrFail(), Vocab::BASE.'level-of-description/item', 'Ceremonial headrest', 'en', null, $this->holder, 'H1');
        $this->actingAs($this->editor)->post("/records/{$record->id}/provenance/overview", ['summary' => 'Collected in 1931; with the museum since 1950.', 'acquisition_type' => 'field-collection',
            'acquisition_price' => '50', 'acquisition_currency' => 'zar', 'research_status' => 'in-progress', 'nazi_era_checked' => '1', 'nazi_era_clear' => '1',
            'cultural_property_status' => 'flagged', 'has_gaps' => '1', 'is_public' => '0'])->assertSessionHasNoErrors();
        $overview = $record->fresh()->provenanceOverview;
        $this->assertSame(['field-collection', '50.00', 'ZAR', true, true, false, 'flagged'], [$overview->acquisitionType->notation, $overview->acquisition_price,
            $overview->acquisition_currency, $overview->nazi_era_checked, $overview->nazi_era_clear, $overview->is_public, $overview->culturalPropertyStatus->notation]);

        $this->actingAs($this->editor)->post("/records/{$record->id}/provenance/documents", ['document_type' => 'bill-of-sale', 'file' => UploadedFile::fake()->createWithContent('receipt.txt', "Received R50\n")])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->post("/records/{$record->id}/provenance/documents", ['document_type' => 'publication', 'title' => 'Museum annual report 1950', 'external_url' => 'https://example.org/report-1950'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->post("/records/{$record->id}/provenance/documents", ['document_type' => 'other'])->assertSessionHasErrors('provenance');
        $receipt = $record->provenanceDocuments()->whereNotNull('storage_path')->sole();
        $this->actingAs($this->editor)->get("/records/{$record->id}/provenance/documents/{$receipt->id}")->assertOk();
        $this->actingAs($this->editor)->get("/records/{$record->id}/provenance")->assertOk()->assertSee('Collected in 1931')->assertSee('receipt.txt')
            ->assertSee('Museum annual report 1950')->assertSee('Nazi-era provenance checked: clear')->assertSee('Not shown publicly');

        // Without update on descriptions, no changes.
        $this->actingAs($this->researcher)->post("/records/{$record->id}/provenance/steps", ['owner_name' => 'X'])->assertForbidden();

        // Kept from the public by its overview, a step is in neither the public page nor the export.
        $this->actingAs($this->editor)->post("/records/{$record->id}/provenance/steps", ['owner_name' => 'Ndlovu, Thabo', 'transfer_type' => 'field-collection'])->assertSessionHasErrors('provenance');
        $this->actingAs($this->editor)->post("/records/{$record->id}/provenance/steps", ['owner_name' => 'Ndlovu, Thabo', 'transfer_type' => 'discovery'])->assertSessionHasNoErrors();
        foreach ([$this->holder, $record] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->editor);
        }
        auth()->logout();
        $this->get(self::SITE."/discover/records/{$record->id}")->assertOk()->assertDontSee('Ndlovu');
        $this->assertArrayNotHasKey('rico:isOrWasAffectedBy', $this->getJson(self::SITE."/api/ric/v1/records/{$record->id}")->assertOk()->json());
    }
}
