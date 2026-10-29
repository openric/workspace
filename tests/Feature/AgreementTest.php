<?php

// OpenRiC Workspace - donor agreements (Heratio's ahg-donor-manage agreements, roadmap phase 9b): made with a number unique
// in the institution, described, with its donor, the accessions it documents and the units it covers as relations, its
// rights, restrictions, documents and reminders, reminders sent when due and moved on when they recur, and staff only.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\PermissionMatrix;
use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\AccessionService;
use App\Description\AgentService;
use App\Description\AgreementService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Mail\AgreementReminderMail;
use App\Models\Agreement;
use App\Models\AgreementReminder;
use App\Models\Identifier;
use App\Models\Institution;
use App\Models\User;
use App\Relations\RelationService;
use App\Support\Vocab;
use App\Vocabulary\SkosImporter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AgreementTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://archives.example.org';

    private User $editor;

    private User $researcher;

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

    private function make(string $number = 'DOG-2026-001', string $title = 'Deed of gift: Botha family papers'): Agreement
    {
        $this->actingAs($this->editor)->post('/agreements', ['number' => $number, 'type' => 'deed-of-gift', 'title' => $title, 'language' => 'en'])->assertRedirect();

        return Agreement::whereNull('deleted_at')->whereIn('id', Identifier::where(['subject_table' => 'agreements', 'value' => $number])->select('subject_id'))->firstOrFail();
    }

    public function test_an_agreement_is_made_described_filtered_and_staff_only(): void
    {
        $agreement = $this->make();
        $this->actingAs($this->editor)->post('/agreements', ['number' => 'DOG-2026-001', 'type' => 'deed-of-gift', 'language' => 'en'])->assertSessionHasErrors('number');
        $this->actingAs($this->editor)->post("/agreements/{$agreement->id}", ['language' => 'en', 'number' => 'DOG-2026-001', 'type' => 'deed-of-gift', 'status' => 'active',
            'agreed_on' => '2026-10-01', 'effective_on' => '2026-10-01', 'expires_on' => now()->addDays(20)->toDateString(), 'legal_representative' => 'J. Smit',
            'has_financial_terms' => '1', 'purchase_amount' => '1500', 'currency' => 'zar', 'donor_signature_name' => 'Anna Botha', 'donor_signed_on' => '2026-10-01',
            'values' => ['agreement-general-terms' => 'Ownership and copyright pass to the archives.', 'agreement-attribution' => 'Gift of the Botha family']])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->post("/agreements/{$agreement->id}", ['language' => 'en', 'number' => 'DOG-2026-001', 'type' => 'deed-of-gift', 'status' => 'active',
            'effective_on' => '2026-10-01', 'expires_on' => '2026-01-01'])->assertSessionHasErrors('values');
        $this->actingAs($this->editor)->get("/agreements/{$agreement->id}")->assertOk()
            ->assertSee('DOG-2026-001 Deed of gift: Botha family papers')->assertSee('Deed of Gift')->assertSee('Active')->assertSee('J. Smit')
            ->assertSee('ZAR 1 500.00')->assertSee('Anna Botha, 2026-10-01')->assertSee('Ownership and copyright pass to the archives.')->assertSee('Gift of the Botha family');
        $this->actingAs($this->editor)->get('/agreements?expiring=30')->assertOk()->assertSee('Botha family papers');
        $this->actingAs($this->editor)->get('/agreements?expiring=7')->assertOk()->assertDontSee('Botha family papers');
        $this->actingAs($this->editor)->get('/agreements?status=draft')->assertOk()->assertDontSee('Botha family papers');
        $this->actingAs($this->editor)->get('/agreements?q=dog-2026')->assertOk()->assertSee('Botha family papers');

        // Staff only: an account with no agreement permission, and the public, never reach it.
        $this->actingAs($this->researcher)->get('/agreements')->assertForbidden();
        $this->actingAs($this->researcher)->get("/agreements/{$agreement->id}")->assertForbidden();
        $this->actingAs($this->editor)->get($agreement->iri)->assertRedirect(route('agreements.show', $agreement));
        auth()->logout();
        $this->get("/agreements/{$agreement->id}")->assertRedirect();
    }

    public function test_its_donor_accessions_records_rights_restrictions_and_documents(): void
    {
        $agreement = $this->make();
        $institution = Institution::firstOrFail();
        $donor = app(AgentService::class)->create($institution, 'person', 'Botha, Anna', 'en');
        $accession = app(AccessionService::class)->create($institution, '2026-014', 'Papers of the Botha family', 'en', $this->editor);
        $repository = app(RepositoryService::class)->create($institution, 'Riverside Archives', 'en', 'RA');
        $fonds = app(RecordService::class)->create($institution, Vocab::BASE.'level-of-description/fonds', 'Botha family papers', 'en', null, $repository, 'BFP');
        $relations = app(RelationService::class);
        $relations->create($agreement, 'creation', $donor, ['role' => 'donor'], $this->editor);
        $relations->create($agreement, 'documentation', $accession, [], $this->editor);
        $relations->create($agreement, 'partly-covers', $fonds, [], $this->editor);
        $this->assertSame(['Botha, Anna'], app(AgreementService::class)->donors($agreement)->map(fn ($a) => $a->described('agent-authorised-name', ['en']))->all());

        foreach ([['rights', ['right' => 'digitise', 'permission' => 'conditional', 'applies_to_digital' => '1', 'requires_donor_approval' => '1', 'conditions' => 'Only for preservation']],
            ['restrictions', ['restriction_type' => 'time-embargo', 'applies_to_all' => '1', 'is_active' => '1', 'ends_on' => '2036-12-31', 'popia_category' => 'personal', 'data_subject_consent' => '0']]] as [$kind, $row]) {
            $this->actingAs($this->editor)->post("/agreements/{$agreement->id}/rows/$kind", ['row' => $row])->assertSessionHasNoErrors();
        }
        $this->actingAs($this->editor)->post("/agreements/{$agreement->id}/rows/restrictions", ['row' => ['restriction_type' => 'closure', 'security_clearance_level' => '12']])
            ->assertSessionHasErrors('agreement');
        $file = UploadedFile::fake()->createWithContent('deed.pdf', "%PDF-1.4\n%deed\n");
        $this->actingAs($this->editor)->post("/agreements/{$agreement->id}/documents", ['file' => $file, 'document_type' => 'signed-agreement', 'is_signed' => '1',
            'signed_on' => '2026-10-01', 'signed_by' => 'Anna Botha', 'is_confidential' => '1'])->assertSessionHasNoErrors();
        $document = $agreement->documents()->sole();
        $this->assertSame(hash('sha256', "%PDF-1.4\n%deed\n"), $document->sha256);

        $this->actingAs($this->editor)->get("/agreements/{$agreement->id}")->assertOk()
            ->assertSee('Was created by Botha, Anna, as Donor')->assertSee('Documents 2026-014 Papers of the Botha family')->assertSee('Covers in part Botha family papers')
            ->assertSee('Digitise')->assertSee('Conditional')->assertSee('Only for preservation')->assertSee('Time embargo')->assertSee('the data subject did not consent')
            ->assertSee('Signed agreement')->assertSee('signed 2026-10-01 by Anna Botha')->assertSee('confidential');
        $this->actingAs($this->editor)->get("/agreements/{$agreement->id}/documents/{$document->id}")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        // The accession shows the agreement that documents it.
        $this->actingAs($this->editor)->get("/accessions/{$accession->id}")->assertOk()->assertSee('Is documented by DOG-2026-001 Deed of gift: Botha family papers');

        // A reader who may not change the agreement does not open a confidential document.
        $reader = app(AccountService::class)->create($institution, 'Reader', 'reader@example.org', 'correct horse battery staple');
        $role = app(RoleService::class)->create($institution, ['en' => ['name' => 'Agreement readers']]);
        app(PermissionMatrix::class)->apply($role, 'agreement', ['read|all' => 'grant']);
        app(RoleService::class)->addMember($role, $reader);
        $this->actingAs($reader)->get("/agreements/{$agreement->id}")->assertOk();
        $this->actingAs($reader)->get("/agreements/{$agreement->id}/documents/{$document->id}")->assertForbidden();

        $this->actingAs($this->editor)->post("/agreements/{$agreement->id}/delete")->assertRedirect(route('agreements.index'));
        $this->actingAs($this->editor)->get("/agreements/{$agreement->id}")->assertNotFound();
        $this->make('DOG-2026-001', 'A new agreement under the same number');
    }

    public function test_reminders_are_sent_when_due_once_and_move_on_when_they_recur(): void
    {
        Mail::fake();
        $agreement = $this->make();
        $this->actingAs($this->editor)->post("/agreements/{$agreement->id}/reminders", ['row' => ['subject' => 'Review the deed', 'type' => 'review-due', 'priority' => 'high',
            'remind_on' => '2026-12-01', 'advance_days' => '10', 'recurrence' => 'yearly', 'notify_email' => '1', 'recipients' => [$this->researcher->id]]])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->post("/agreements/{$agreement->id}/reminders", ['row' => ['subject' => 'Ring the donor', 'type' => 'donor-contact',
            'remind_on' => '2026-11-15', 'advance_days' => '0', 'notify_email' => '1']])->assertSessionHasNoErrors();
        $review = AgreementReminder::where('subject', 'Review the deed')->sole();
        $ring = AgreementReminder::where('subject', 'Ring the donor')->sole();
        $service = app(AgreementService::class);

        // Not yet in its warning period.
        $this->assertSame(['sent' => 0, 'unsent' => 0], $service->sendDue(CarbonImmutable::parse('2026-11-14')));
        // The researcher may not read agreements, so the reminder sent to the researcher alone stays due.
        $this->assertSame(['sent' => 1, 'unsent' => 1], $service->sendDue(CarbonImmutable::parse('2026-11-21')));
        Mail::assertQueued(AgreementReminderMail::class, fn ($mail) => $mail->hasTo('editor@example.org') && $mail->reminder->is($ring) && $mail->due === '2026-11-15');
        $this->assertSame('2026-11-15', $ring->fresh()->sent_for->toDateString());
        $this->assertSame(['sent' => 0, 'unsent' => 1], $service->sendDue(CarbonImmutable::parse('2026-11-22')), 'a date is sent once');

        // Sent to the editor instead, it goes, and its next date is a year on.
        $review->recipients()->sync([$this->editor->id]);
        $this->assertSame(['sent' => 1, 'unsent' => 0], $service->sendDue(CarbonImmutable::parse('2026-11-22')));
        $this->assertSame('2027-12-01', $review->fresh()->remind_on->toDateString());

        // Snoozed, it waits; completed, it is done.
        $this->actingAs($this->editor)->post("/agreements/{$agreement->id}/reminders/{$ring->id}/status", ['status' => 'snoozed', 'snoozed_until' => '2020-01-01'])->assertSessionHasErrors('agreement');
        $this->actingAs($this->editor)->post("/agreements/{$agreement->id}/reminders/{$review->id}/status", ['status' => 'snoozed', 'snoozed_until' => now()->addYears(2)->toDateString()])
            ->assertSessionHasNoErrors();
        $this->assertSame(['sent' => 0, 'unsent' => 0], $service->sendDue(CarbonImmutable::parse('2027-11-25')));
        $this->actingAs($this->editor)->post("/agreements/{$agreement->id}/reminders/{$ring->id}/status", ['status' => 'completed', 'completion_note' => 'Spoke to Anna', 'back' => 'reminders'])
            ->assertRedirect(route('agreements.reminders'));
        $this->actingAs($this->editor)->get('/agreements/reminders')->assertOk()->assertSee('Review the deed')->assertDontSee('Ring the donor');
        $this->actingAs($this->editor)->get('/agreements/reminders?status=completed')->assertOk()->assertSee('Ring the donor');
    }
}
