<?php

// OpenRiC Workspace - the intake of accessions (PSIS's accessionIntake, Heratio's intake queue, finalise, intake
// configuration and numbering; roadmap phase 9c): numbers from the institution's masks, PSIS's workflow with Heratio's
// requirement of an agreement before accepting, assignment, the checklist from a template, attachments, the timeline
// and the queue.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\AgreementService;
use App\Models\Accession;
use App\Models\Identifier;
use App\Models\Institution;
use App\Models\User;
use App\Relations\RelationService;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AccessionIntakeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $editor;

    private string $store;

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

    private function accession(string $number): Accession
    {
        return Accession::whereIn('id', Identifier::where(['subject_table' => 'accessions', 'value' => $number])->select('subject_id'))->sole();
    }

    public function test_numbers_come_from_the_masks_and_skip_those_typed_in(): void
    {
        $year = date('Y');
        $this->actingAs($this->admin)->post('/settings/accessions', ['accession_numbering_mask' => 'ACC-{YEAR}-{SEQ:3}', 'agreement_numbering_mask' => '{TYPE}-{YEAR}-{SEQ:3}'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/settings/accessions', ['accession_numbering_mask' => 'ACC-{YEAR}', 'agreement_numbering_mask' => '{TYPE}-{SEQ}'])->assertSessionHasErrors('accession_numbering_mask');
        $this->actingAs($this->editor)->post('/settings/accessions', ['accession_numbering_mask' => '{SEQ}', 'agreement_numbering_mask' => '{SEQ}'])->assertForbidden();

        $this->actingAs($this->editor)->post('/accessions', ['number' => "ACC-$year-002", 'title' => 'Typed in', 'language' => 'en'])->assertRedirect();
        $this->actingAs($this->editor)->post('/accessions', ['title' => 'First given', 'language' => 'en'])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->post('/accessions', ['title' => 'Second given', 'language' => 'en'])->assertRedirect();
        $this->assertSame('First given', $this->accession("ACC-$year-001")->described('accession-title', ['en']));
        $this->assertSame('Second given', $this->accession("ACC-$year-003")->described('accession-title', ['en']), 'the number typed in is skipped');

        $agreement = app(AgreementService::class)->create(Institution::firstOrFail(), null, 'deed-of-gift', 'A deed', 'en', $this->editor);
        $this->assertSame("DOG-$year-001", $agreement->number());
        $this->assertSame("LOA-$year-001", app(AgreementService::class)->create(Institution::firstOrFail(), '', 'loan-agreement', null, 'en', $this->editor)->number());
        $this->actingAs($this->admin)->get('/settings/accessions')->assertOk()->assertSee('Standard Intake')->assertSee("last 3 in $year");
    }

    public function test_the_workflow_with_an_agreement_required_assignment_checklist_attachments_and_timeline(): void
    {
        Institution::firstOrFail()->update(['accession_requires_agreement' => true, 'accession_auto_assign' => true]);
        $this->actingAs($this->editor)->post('/accessions', ['number' => '2026-014', 'title' => 'Papers of the Botha family', 'language' => 'en'])->assertRedirect();
        $accession = $this->accession('2026-014');
        $this->assertSame([$this->editor->id, 'draft', 'normal', 6], [$accession->assigned_to_id, $accession->intakeStatus->notation, $accession->intakePriority->notation, $accession->checklist()->count()],
            'assigned to who recorded it, in draft, with the standard checklist');

        $this->actingAs($this->editor)->post("/accessions/{$accession->id}/intake", ['to' => 'accepted'])->assertSessionHasErrors('accession');
        foreach (['submitted', 'under-review'] as $to) {
            $this->actingAs($this->editor)->post("/accessions/{$accession->id}/intake", ['to' => $to])->assertSessionHasNoErrors();
        }
        $this->actingAs($this->editor)->post("/accessions/{$accession->id}/intake", ['to' => 'accepted'])->assertSessionHasErrors('accession');
        $this->actingAs($this->editor)->get("/accessions/{$accession->id}")->assertOk()->assertSee('Before it can be accepted: no donor agreement documents it.');
        $agreement = app(AgreementService::class)->create(Institution::firstOrFail(), 'DOG-1', 'deed-of-gift', null, 'en', $this->editor);
        app(RelationService::class)->create($agreement, 'documentation', $accession, [], $this->editor);
        $this->actingAs($this->editor)->post("/accessions/{$accession->id}/intake", ['to' => 'accepted'])->assertSessionHasNoErrors();
        $this->assertNotNull($accession->fresh()->accepted_at);
        $this->actingAs($this->editor)->post("/accessions/{$accession->id}/intake", ['to' => 'submitted'])->assertSessionHasErrors('accession');

        $item = $accession->checklist()->first();
        $this->actingAs($this->editor)->post("/accessions/{$accession->id}/checklist", ['action' => 'toggle', 'item' => $item->id])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->post("/accessions/{$accession->id}/checklist", ['action' => 'add', 'label' => 'Boxes labelled'])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->post("/accessions/{$accession->id}/attachments", ['file' => UploadedFile::fake()->createWithContent('inventory.txt', "Box 1: letters\n"),
            'category' => 'inventory'])->assertSessionHasNoErrors();
        $attachment = $accession->attachments()->sole();
        $this->actingAs($this->editor)->get("/accessions/{$accession->id}/attachments/{$attachment->id}")->assertOk();
        $this->actingAs($this->editor)->post("/accessions/{$accession->id}/assign", ['assignee' => $this->admin->id, 'priority' => 'urgent'])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->post("/accessions/{$accession->id}/timeline", ['note' => 'Collected from the donor\'s house'])->assertSessionHasNoErrors();

        $this->actingAs($this->editor)->get("/accessions/{$accession->id}")->assertOk()
            ->assertSee('Accepted')->assertSee('Urgent')->assertSee('1 of 7 done (14%)')->assertSee('Boxes labelled')->assertSee('inventory.txt')
            ->assertSeeInOrder(['Collected from the donor', 'Assigned to Admin', 'Attachment added: inventory.txt', 'Accession accepted', 'Accession under review',
                'Accession submitted for review', 'Accession created']);
    }

    public function test_the_queue_filters_and_counts(): void
    {
        $this->actingAs($this->editor)->post('/accessions', ['number' => 'A-1', 'title' => 'Open one', 'language' => 'en']);
        $this->actingAs($this->editor)->post('/accessions', ['number' => 'A-2', 'title' => 'Submitted one', 'language' => 'en']);
        $this->actingAs($this->editor)->post('/accessions/'.$this->accession('A-2')->id.'/intake', ['to' => 'submitted']);
        $this->actingAs($this->editor)->post('/accessions/'.$this->accession('A-2')->id.'/intake', ['to' => 'under-review']);
        $this->actingAs($this->editor)->post('/accessions/'.$this->accession('A-2')->id.'/intake', ['to' => 'rejected', 'text' => 'Out of scope'])->assertSessionHasNoErrors();

        $this->actingAs($this->editor)->get('/accessions/queue')->assertOk()->assertSee('Open one')->assertDontSee('Submitted one')->assertSee('Draft 1')->assertSee('Rejected 1');
        $this->actingAs($this->editor)->get('/accessions/queue?status=rejected')->assertOk()->assertSee('Submitted one')->assertDontSee('Open one');
        $this->actingAs($this->editor)->get('/accessions/queue?assigned=me')->assertOk()->assertDontSee('Open one');
        $this->actingAs($this->editor)->get('/accessions/'.$this->accession('A-2')->id)->assertOk()->assertSee('Out of scope');
    }
}
