<?php

// OpenRiC Workspace - the jurisdiction packs: a transfer packaged for the National Archives and Records Service of
// South Africa, an NHRA s.32 export permit through its workflow, the National Archives of Zimbabwe's closures,
// protections, research permits, schedules and transfers, and the Cyber and Data Protection Act's controller licences
// (roadmap phases 18d and 18e, ADR 0016).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\InstantiationService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Exchange\ExportView;
use App\Files\FileStore;
use App\Models\Concept;
use App\Models\ControllerLicence;
use App\Models\ExportPermit;
use App\Models\Institution;
use App\Models\NarssaTransfer;
use App\Models\NazRecordsSchedule;
use App\Models\NazResearchPermit;
use App\Models\NazRestriction;
use App\Models\NazTransfer;
use App\Models\RecordResource;
use App\Models\Researcher;
use App\Models\User;
use App\Support\Vocab;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use PharData;
use Tests\TestCase;

class JurisdictionPacksTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private string $store;

    private Institution $institution;

    /** @var array<string, User> */
    private array $people = [];

    private RecordResource $fonds;

    private RecordResource $letter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        $this->travelTo('2026-11-05 09:00');
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        foreach (['officer' => 'administrator', 'editor' => 'editor', 'colleague' => 'editor'] as $who => $template) {
            $this->people[$who] = app(AccountService::class)->create($this->institution, ucfirst($who), "$who@example.org", 'correct horse battery staple');
            app(AccountService::class)->grantTemplateRole($this->people[$who], $template);
        }
        $this->people['reader'] = app(AccountService::class)->create($this->institution, 'Reader', 'reader@example.org', 'correct horse battery staple');
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $this->fonds = app(RecordService::class)->create($this->institution, self::LEVEL.'fonds', 'Native Affairs records', 'en', null, $holder, 'NA');
        $this->letter = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Letter about the land claim', 'en', $this->fonds, null, '1');
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($path, "%PDF-1.4\n% a letter\n");
        app(InstantiationService::class)->upload($this->letter, $path, 'letter.pdf', 'en', 'Scan of the letter', null, 'online', $this->people['editor']);
        foreach ([$holder, $this->fonds, $this->letter] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->people['officer']);
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->store)) {
            exec('rm -rf '.escapeshellarg($this->store));
        }
        parent::tearDown();
    }

    public function test_south_africa_narssa_transfer_and_export_permit(): void
    {
        $editor = $this->people['editor'];
        $colleague = $this->people['colleague'];
        // The packs are chosen in the settings: off, a pack has no menu entry and its pages answer 404.
        $this->actingAs($editor)->get(route('jurisdiction.narssa'))->assertNotFound();
        $this->actingAs($this->people['officer'])->post(route('settings.update'), ['name' => 'Riverside Archives', 'jurisdiction_packs' => ['', 'narssa', 'export-permits']])->assertSessionHasNoErrors();
        $this->assertTrue($this->institution->fresh()->usesPack('export-permits'));
        $editor = $editor->fresh();
        $this->actingAs($editor)->get(route('jurisdiction.naz'))->assertNotFound();
        $this->actingAs($editor)->get(route('jurisdiction.permits'))->assertOk()->assertSee(__('Transfers to the National Archives'))->assertDontSee(__('National Archives of Zimbabwe'));
        $this->actingAs($editor)->get(route('jurisdiction.narssa'))->assertOk()->assertSee(__('Transfers to the National Archives'));
        $this->actingAs($this->people['reader'])->get(route('jurisdiction.narssa'))->assertForbidden();
        // Nothing approved waits for a transfer; a record not named by its IRI is refused.
        $this->actingAs($editor)->post(route('jurisdiction.narssa.build'), ['from_disposals' => 1])->assertSessionHasErrors('jurisdiction');
        $this->actingAs($editor)->post(route('jurisdiction.narssa.build'), ['record_iris' => "https://nowhere.example.org/x\n".$this->letter->iri])->assertSessionHasErrors('jurisdiction');

        // The package: one folder a record with its EAD3 and master files, a manifest and a METS file.
        $this->actingAs($editor)->post(route('jurisdiction.narssa.build'), ['record_iris' => $this->fonds->iri."\n".$this->letter->iri, 'title' => 'Native Affairs, first transfer'])
            ->assertRedirect(route('jurisdiction.narssa'));
        $transfer = NarssaTransfer::sole();
        $this->assertSame('NARSSA-2026-001', $transfer->reference);
        $this->assertSame(2, $transfer->item_count);
        $this->assertSame('packaged', $transfer->status->notation);
        $this->assertSame($transfer->package_sha256, hash_file('sha256', app(FileStore::class)->path($transfer->package_path)));
        copy(app(FileStore::class)->path($transfer->package_path), $path = $this->store.'/package.tar.gz');
        $tar = new PharData($path);
        $names = [];
        foreach (new \RecursiveIteratorIterator($tar) as $file) {
            $names[] = substr($file->getPathname(), strlen('phar://'.$path.'/'));
        }
        $this->assertContains('NARSSA-2026-001/manifest.csv', $names);
        $this->assertContains('NARSSA-2026-001/transfer.xml', $names);
        $this->assertContains('NARSSA-2026-001/items/RA_NA_1/files/letter.pdf', $names);
        $this->assertStringContainsString('<ead', file_get_contents('phar://'.$path.'/NARSSA-2026-001/items/RA_NA_1/description.xml'));
        $mets = file_get_contents('phar://'.$path.'/NARSSA-2026-001/transfer.xml');
        $this->assertStringContainsString('CHECKSUMTYPE="SHA-256"', $mets);
        $this->assertStringContainsString('Native Affairs records', $mets);
        $this->actingAs($editor)->get(route('jurisdiction.narssa.package', $transfer))->assertOk()->assertHeader('X-Content-SHA256', $transfer->package_sha256);

        // Transmitted, then accepted only with the receipt's reference.
        $this->actingAs($editor)->post(route('jurisdiction.narssa.step', [$transfer, 'accepted']), ['receipt_reference' => 'R1'])->assertSessionHasErrors('jurisdiction');
        $this->actingAs($editor)->post(route('jurisdiction.narssa.step', [$transfer, 'transmitted']))->assertSessionHasNoErrors();
        $this->actingAs($editor)->post(route('jurisdiction.narssa.step', [$transfer, 'accepted']))->assertSessionHasErrors('jurisdiction');
        $this->actingAs($editor)->post(route('jurisdiction.narssa.step', [$transfer, 'accepted']), ['receipt_reference' => 'NARS/2026/114'])->assertSessionHasNoErrors();
        $this->assertSame('accepted', $transfer->fresh()->status->notation);
        $this->assertSame('NARS/2026/114', $transfer->fresh()->receipt_reference);

        // An export permit: drafted by its applicant, approved by someone else, lodged, issued with its number.
        $this->actingAs($editor)->post(route('jurisdiction.permits.store'), ['purpose' => 'Loan for an exhibition', 'record_iris' => $this->letter->iri, 'destination_country' => 'B1'])
            ->assertSessionHasErrors('jurisdiction');
        $this->actingAs($editor)->post(route('jurisdiction.permits.store'), ['purpose' => 'Loan for an exhibition', 'record_iris' => $this->letter->iri, 'destination_institution' => 'Khama III Memorial Museum',
            'destination_country' => 'bw', 'start_date' => '2026-12-01', 'end_date' => '2027-02-28'])->assertRedirect();
        $permit = ExportPermit::sole();
        $this->assertSame('SAHRA-APP-2026-0001', $permit->application_ref);
        $this->assertSame('BW', $permit->destination_country);
        $this->assertSame('draft', $permit->status->notation);
        $this->actingAs($editor)->get(route('jurisdiction.permit', $permit))->assertOk()->assertSee('Khama III Memorial Museum');
        $step = fn (User $who, string $s, array $data = []) => $this->actingAs($who)->post(route('jurisdiction.permits.step', [$permit, $s]), $data);
        $step($colleague, 'submit-for-approval')->assertSessionHasErrors('jurisdiction');
        $step($editor, 'submit-for-approval')->assertSessionHasNoErrors();
        $step($editor, 'approve')->assertSessionHasErrors('jurisdiction');
        $step($colleague, 'approve', ['notes' => 'Insured and couriered'])->assertSessionHasNoErrors();
        $this->assertSame($colleague->id, $permit->fresh()->approved_by_id);
        $step($colleague, 'lodge', ['authority_reference' => 'SAHRIS 4471'])->assertSessionHasNoErrors();
        $step($colleague, 'issued')->assertSessionHasErrors('jurisdiction');
        $step($colleague, 'issued', ['permit_number' => '32/26/11/001', 'conditions' => 'Return by 28 February 2027'])->assertSessionHasNoErrors();
        $this->assertSame('issued', $permit->fresh()->status->notation);
        $this->actingAs($editor)->post(route('jurisdiction.permits.step', [$permit, 'document']), ['file' => UploadedFile::fake()->createWithContent('permit.pdf', "%PDF-1.4\n"), 'kind' => 'Permit'])
            ->assertSessionHasNoErrors();
        $this->actingAs($editor)->get(route('jurisdiction.permits.document', $permit->documents()->sole()))->assertOk();

        // An issued permit expires the day after its period ends.
        $this->travelTo('2027-03-01 09:00');
        Artisan::call('workspace:jurisdiction-daily');
        $this->assertSame('expired', $permit->fresh()->status->notation);
        $step($colleague, 'close')->assertSessionHasNoErrors();
        $this->assertSame('closed', $permit->fresh()->status->notation);

        // Another institution sees none of it.
        $other = Institution::create(['name' => 'Elsewhere', 'base_iri' => 'https://elsewhere.example.org']);
        app(RoleService::class)->seedDefaults($other);
        $stranger = app(AccountService::class)->create($other, 'Stranger', 'stranger@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($stranger, 'editor');
        $this->actingAs($stranger)->get(route('jurisdiction.permit', $permit))->assertNotFound();
        $this->actingAs($stranger)->get(route('jurisdiction.narssa.package', $transfer))->assertNotFound();
    }

    public function test_zimbabwe_closures_permits_schedules_transfers_and_licences(): void
    {
        $editor = $this->people['editor'];
        $colleague = $this->people['colleague'];
        $this->actingAs($this->people['officer'])->get(route('privacy.register', 'licences'))->assertNotFound();
        $this->institution->jurisdictionPacks()->attach(Concept::whereIn('iri', [Vocab::BASE.'jurisdiction-pack/naz', Vocab::BASE.'jurisdiction-pack/cdpa'])->pluck('id'));
        $this->actingAs($editor)->get(route('jurisdiction.naz'))->assertOk()->assertSee(__('National Archives of Zimbabwe'));
        $public = fn () => (new ExportView(null, false, null, $this->institution))->readable($this->letter->fresh());
        $this->assertTrue($public());

        // A closure of 25 years, the records inside included, is the embargo that keeps them from the public.
        $this->actingAs($editor)->post(route('jurisdiction.naz.step', 'restrict'), ['iri' => $this->fonds->iri, 'kind' => 'closure', 'type' => 'ministerial'])->assertSessionHasErrors('jurisdiction');
        $this->actingAs($editor)->post(route('jurisdiction.naz.step', 'restrict'), ['iri' => $this->fonds->iri, 'kind' => 'closure', 'type' => 'standard', 'years' => 25, 'inside' => 1])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, NazRestriction::count());
        $closure = NazRestriction::where('record_resource_id', $this->letter->id)->sole();
        $this->assertSame('2051-11-05', $closure->embargo->ends_on->toDateString());
        $this->assertFalse($public());
        // A protection must say why.
        $this->actingAs($editor)->post(route('jurisdiction.naz.step', 'restrict'), ['iri' => $this->letter->iri, 'kind' => 'protection', 'type' => 'personal'])->assertSessionHasErrors('jurisdiction');
        $this->actingAs($editor)->post(route('jurisdiction.naz.step', ['release', $closure->id]), ['reason' => 'Opened by the Director'])->assertSessionHasNoErrors();
        $this->assertNotNull($closure->embargo->fresh()->lifted_at);

        // A research permit: approved by someone other than who entered it; active once its period begins, expired after.
        $researcher = Researcher::create(['institution_id' => $this->institution->id, 'user_id' => $this->people['reader']->id, 'first_name' => 'Tendai', 'last_name' => 'Moyo',
            'affiliation_id' => $this->concept('researcher-affiliation', 'student'), 'status_id' => $this->concept('researcher-status', 'approved')]);
        $permitData = ['researcher_id' => $researcher->id, 'permit_type' => 'restricted', 'research_topic' => 'Land tenure in Matabeleland, 1930-1960', 'start_date' => '2026-12-01', 'end_date' => '2027-05-31',
            'fee_amount' => '200', 'fee_currency' => 'USD', 'fee_paid' => 1];
        $this->actingAs($editor)->post(route('jurisdiction.naz.step', 'permit'), $permitData)->assertSessionHasNoErrors();
        $permit = NazResearchPermit::sole();
        $this->assertSame('NAZ-RP-2026-0001', $permit->permit_number);
        $this->actingAs($editor)->post(route('jurisdiction.naz.step', ['approve', $permit->id]))->assertSessionHasErrors('jurisdiction');
        $this->actingAs($colleague)->post(route('jurisdiction.naz.step', ['approve', $permit->id]))->assertSessionHasNoErrors();
        $this->assertSame('approved', $permit->fresh()->status->notation);
        $this->travelTo('2026-12-02 09:00');
        Artisan::call('workspace:jurisdiction-daily');
        $this->assertSame('active', $permit->fresh()->status->notation);
        $this->actingAs($colleague)->post(route('jurisdiction.naz.step', ['revoke', $permit->id]))->assertSessionHasErrors('jurisdiction');
        $this->actingAs($colleague)->post(route('jurisdiction.naz.step', ['revoke', $permit->id]), ['reason' => 'Conditions broken'])->assertSessionHasNoErrors();
        $this->assertSame('revoked', $permit->fresh()->status->notation);

        // A records schedule, approved only with who approved it; a transfer under it, accessioned only with its number.
        $schedule = ['agency_name' => 'Ministry of Lands', 'record_series' => 'Land allocation files', 'retention_active_years' => 5, 'retention_semi_active_years' => 10, 'disposal_action' => 'transfer',
            'records_class' => 'important', 'status' => 'approved'];
        $this->actingAs($editor)->post(route('jurisdiction.naz.step', 'schedule'), $schedule)->assertSessionHasErrors('jurisdiction');
        $this->actingAs($editor)->post(route('jurisdiction.naz.step', 'schedule'), $schedule + ['approved_by' => 'Director of the National Archives'])->assertSessionHasNoErrors();
        $sched = NazRecordsSchedule::sole();
        $this->assertSame('NAZ-RS-2026-0001', $sched->schedule_number);
        $transfer = ['transferring_agency' => 'Ministry of Lands', 'schedule_id' => $sched->id, 'transfer_type' => 'scheduled', 'quantity_boxes' => 40, 'status' => 'accessioned'];
        $this->actingAs($editor)->post(route('jurisdiction.naz.step', 'transfer'), $transfer)->assertSessionHasErrors('jurisdiction');
        $this->actingAs($editor)->post(route('jurisdiction.naz.step', 'transfer'), $transfer + ['accession_number' => 'ACC 2026/9'])->assertSessionHasNoErrors();
        $this->assertSame($editor->id, NazTransfer::sole()->received_by_id);
        $this->actingAs($editor)->get(route('jurisdiction.naz'))->assertOk()->assertSee('NAZ-RS-2026-0001')->assertSee('ACC 2026/9')->assertSee('Moyo');

        // The Cyber and Data Protection Act: a jurisdiction of the privacy registers, its controller licences expiring.
        $this->actingAs($this->people['officer'])->post(route('privacy.store', 'licences'), ['jurisdiction_id' => 'cdpa', 'licence_number' => 'POTRAZ/DP/0417', 'tier_id' => 'tier-1',
            'organisation_name' => 'Riverside Archives', 'registered_on' => '2026-01-10', 'issued_on' => '2026-01-15', 'expires_on' => '2026-12-31'])->assertSessionHasNoErrors();
        $licence = ControllerLicence::sole();
        $this->assertSame('cdpa', $licence->jurisdiction->code);
        $this->assertSame('active', $licence->status->notation);
        $this->travelTo('2027-01-01 09:00');
        Artisan::call('workspace:jurisdiction-daily');
        $this->assertSame('expired', $licence->fresh()->status->notation);
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.$vocabulary.'/'.$notation)->value('id');
    }
}
