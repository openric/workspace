<?php

// OpenRiC Workspace - lectures, training and certificates, quotas, notices, the inbox, research documents, print
// templates and rights policies on records (roadmap phase 19j, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Mail\ResearchMail;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\ResearchDocument;
use App\Models\ResearchDocumentTemplate;
use App\Models\ResearchInboxItem;
use App\Models\ResearchLecture;
use App\Models\ResearchNotification;
use App\Models\ResearchPrintTemplate;
use App\Models\ResearchProject;
use App\Models\ResearchWorkspace;
use App\Models\TrainingCertificate;
use App\Models\TrainingCourse;
use App\Models\User;
use App\Research\DocumentTemplates;
use App\Research\Notifications;
use App\Research\RightsPolicies;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ResearchDeskTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private string $store;

    private Institution $institution;

    private User $admin;

    private User $reader;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        Mail::fake();
        $this->travelTo('2026-11-05 09:00');
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->admin = app(AccountService::class)->create($this->institution, 'Admin', 'admin@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->admin, 'administrator');
        $this->reader = app(AccountService::class)->create($this->institution, 'Rita', 'rita@example.org', 'correct horse battery staple');
        $this->other = app(AccountService::class)->create($this->institution, 'Olu', 'olu@example.org', 'correct horse battery staple');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->store)) {
            exec('rm -rf '.escapeshellarg($this->store));
        }
        parent::tearDown();
    }

    public function test_a_course_is_taken_module_by_module_and_a_pass_earns_one_certificate_that_anyone_can_check(): void
    {
        $this->actingAs($this->admin)->post(route('research.lectures.save'), ['title' => 'Reading council minutes', 'type' => 'curriculum', 'institutional' => 1])->assertRedirect();
        $lecture = ResearchLecture::sole();
        $this->assertNull($lecture->user_id);
        $this->actingAs($this->admin)->post(route('research.lectures.step', [$lecture, 'section']), ['heading' => 'Minute books', 'body' => "Minutes are **signed**.\n\n<script>alert(1)</script>"])->assertSessionHasNoErrors();
        // A draft lecture is its keeper's alone.
        $this->actingAs($this->reader)->get(route('research.lectures.show', $lecture))->assertNotFound();
        $this->actingAs($this->admin)->post(route('research.lectures.save', $lecture), ['title' => 'Reading council minutes', 'type' => 'curriculum', 'status' => 'published'])->assertSessionHasNoErrors();
        $this->actingAs($this->reader)->get(route('research.lectures.show', $lecture))->assertOk()->assertSee('<strong>signed</strong>', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->actingAs($this->reader)->post(route('research.lectures.step', [$lecture, 'section']), ['heading' => 'Mine'])->assertSessionHasErrors(['research' => 'That is not yours.']);

        $this->actingAs($this->admin)->post(route('research.courses.save'), ['title' => 'Archives for beginners', 'pass_mark' => 50, 'institutional' => 1])->assertRedirect();
        $course = TrainingCourse::sole();
        $this->actingAs($this->reader)->get(route('research.teaching'))->assertOk()->assertSee('Reading council minutes')->assertDontSee('Archives for beginners');
        $this->actingAs($this->admin)->post(route('research.courses.step', [$course, 'module']), ['title' => 'Minutes', 'lecture_id' => $lecture->id])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('research.courses.step', [$course, 'assessment']), ['questions' => [
            ['question' => 'Who signs the minutes?', 'options' => ['The chair', 'The clerk', 'Nobody', ''], 'answer' => 0],
            ['question' => 'Where are they kept?', 'options' => ['In the minute book', 'In a drawer'], 'answer' => 0],
            ['question' => '', 'options' => ['', ''], 'answer' => 0],
        ]])->assertSessionHasNoErrors();
        $this->assertCount(2, $course->assessment()->sole()->questions);
        $this->actingAs($this->admin)->post(route('research.courses.step', [$course, 'assessment']), ['questions' => [['question' => 'Only one option?', 'options' => ['Yes'], 'answer' => 0]]])
            ->assertSessionHasErrors('research');
        $this->actingAs($this->reader)->post(route('research.courses.step', [$course, 'enrol']))->assertSessionHasErrors(['research' => 'That course is not open for enrolment.']);
        $this->actingAs($this->admin)->post(route('research.courses.save', $course), ['title' => 'Archives for beginners', 'pass_mark' => 50, 'status' => 'published'])->assertSessionHasNoErrors();

        $this->actingAs($this->reader)->post(route('research.courses.step', [$course, 'enrol']))->assertSessionHasNoErrors();
        $enrolment = $course->enrolments()->sole();
        // The right answers are not sent with the questions.
        $page = $this->actingAs($this->reader)->get(route('research.courses.show', $course))->assertOk()->assertSee('Who signs the minutes?')->getContent();
        $this->assertStringNotContainsString('"answer"', $page);
        $this->actingAs($this->reader)->post(route('research.enrolments.step', [$enrolment, 'submit']), ['answers' => [1, 1]])->assertSessionHas('status', 'Not passed: 0%, the pass mark is 50%.');
        // Passed, but a module is not done yet: no certificate.
        $this->actingAs($this->reader)->post(route('research.enrolments.step', [$enrolment, 'submit']), ['answers' => [0, 1]])->assertSessionHas('status', 'Passed with 50%.');
        $this->assertSame(0, TrainingCertificate::count());
        $this->actingAs($this->reader)->post(route('research.enrolments.step', [$enrolment, 'done', $course->modules()->sole()->id]))->assertSessionHasNoErrors();
        $this->actingAs($this->reader)->post(route('research.enrolments.step', [$enrolment, 'submit']), ['answers' => [0, 0]]);
        $this->actingAs($this->reader)->post(route('research.enrolments.step', [$enrolment, 'submit']), ['answers' => [0, 0]]);
        $certificate = TrainingCertificate::sole();
        $this->assertMatchesRegularExpression('/^CERT-2026-[A-Z0-9]{8}$/', $certificate->certificate_no);
        $this->assertSame([100, 'completed'], [$enrolment->fresh()->score, $enrolment->fresh()->status->notation]);
        $this->actingAs($this->other)->post(route('research.enrolments.step', [$enrolment, 'submit']), ['answers' => [0, 0]])->assertSessionHasErrors(['research' => 'That is not yours.']);
        auth()->logout();
        $this->get(route('research.certificates.show', $certificate->certificate_no))->assertOk()->assertSee('Rita completed Archives for beginners with 100% on 5 November 2026.');
        $this->get('/certificates/CERT-2026-NOTREAL1')->assertNotFound();
    }

    public function test_quotas_refuse_past_the_limit_with_the_narrowest_policy_winning(): void
    {
        $this->actingAs($this->reader)->post(route('research.projects.store'), ['title' => 'The parish bridge', 'project_type' => 'thesis', 'status' => 'active', 'visibility' => 'private']);
        $project = ResearchProject::sole();
        $this->actingAs($this->reader)->get(route('research.quotas'))->assertForbidden();
        $this->actingAs($this->admin)->post(route('research.quotas.save'), ['scope' => 'global', 'period' => 'monthly', 'max_downloads' => 1, 'is_active' => 1])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('research.quotas.save'), ['scope' => 'user', 'scope_key' => $this->reader->id, 'period' => 'monthly', 'max_downloads' => 2, 'soft_warn_pct' => 50, 'is_active' => 1])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('research.quotas.save'), ['scope' => 'global', 'period' => 'monthly', 'max_downloads' => 5, 'is_active' => 1])
            ->assertSessionHasErrors(['research' => 'There is a limit for them and that period already; change that one.']);

        $this->actingAs($this->reader)->get(route('research.projects.export', [$project, 'json']))->assertOk();
        $this->actingAs($this->reader)->get(route('research.projects.export', [$project, 'json']))->assertOk();
        $this->actingAs($this->reader)->from(route('research.projects.show', $project))->get(route('research.projects.export', [$project, 'json']))
            ->assertRedirect(route('research.projects.show', $project))->assertSessionHasErrors(['research' => 'You have reached your download limit of 2 this month. Ask the reading room if you need more.']);
        // A new month starts the count again.
        $this->travelTo('2026-12-01 09:00');
        $this->actingAs($this->reader)->get(route('research.projects.export', [$project, 'json']))->assertOk();

        $this->actingAs($this->admin)->post(route('research.quotas.save'), ['scope' => 'user', 'scope_key' => $this->reader->id, 'period' => 'total', 'max_storage_mb' => 1, 'is_active' => 1])->assertSessionHasNoErrors();
        $this->actingAs($this->reader)->post(route('research.workspaces.store'), ['name' => 'Bridge papers', 'visibility' => 'private'])->assertSessionHasNoErrors();
        $workspace = ResearchWorkspace::sole();
        $this->actingAs($this->reader)->post(route('research.workspaces.step', [$workspace, 'file']), ['file' => UploadedFile::fake()->createWithContent('ledger.txt', str_repeat('a', 700 * 1024))])->assertSessionHasNoErrors();
        $this->actingAs($this->reader)->post(route('research.workspaces.step', [$workspace, 'file']), ['file' => UploadedFile::fake()->createWithContent('more.txt', str_repeat('b', 400 * 1024))])
            ->assertSessionHasErrors(['research' => 'That would take you past your storage limit of 1 MB. Remove files you no longer need, or ask the reading room.']);
        $this->actingAs($this->admin)->get(route('research.quotas'))->assertOk()->assertSee('Rita')->assertSee('700 KB / 1 MB');
    }

    public function test_research_notices_are_kept_and_mailed_as_each_researcher_prefers(): void
    {
        $this->actingAs($this->reader)->post(route('research.projects.store'), ['title' => 'The parish bridge', 'project_type' => 'thesis', 'status' => 'active', 'visibility' => 'private']);
        $project = ResearchProject::sole();
        $this->actingAs($this->reader)->post(route('research.projects.step', [$project, 'invite']), ['email' => 'olu@example.org', 'role' => 'editor'])->assertSessionHasNoErrors();
        Mail::assertQueued(ResearchMail::class, 1);
        $notice = ResearchNotification::sole();
        $this->assertSame(['invitation', 'An invitation to the research project The parish bridge'], [$notice->type->notation, $notice->title]);
        $this->assertNotNull($notice->emailed_at);
        $this->assertSame(1, Notifications::unread($this->other));
        $this->actingAs($this->other)->get(route('research.notifications'))->assertOk()->assertSee('An invitation to the research project The parish bridge');
        $this->actingAs($this->other)->get(route('research.notifications.open', $notice))->assertRedirect();
        $this->assertSame(0, Notifications::unread($this->other));
        $this->actingAs($this->reader)->get(route('research.notifications.open', $notice))->assertNotFound();

        // Daily digests of invitations, and nothing of the kind shown in the Workspace.
        $this->actingAs($this->admin)->post(route('research.notifications.preferences'), ['prefs' => ['invitation' => ['email' => 1, 'digest' => 'daily']]])->assertSessionHasNoErrors();
        $this->actingAs($this->reader)->post(route('research.projects.store'), ['title' => 'Second', 'project_type' => 'thesis', 'status' => 'active', 'visibility' => 'private']);
        $second = ResearchProject::where('title', 'Second')->sole();
        $this->actingAs($this->reader)->post(route('research.projects.step', [$second, 'invite']), ['email' => 'admin@example.org', 'role' => 'viewer'])->assertSessionHasNoErrors();
        Mail::assertQueued(ResearchMail::class, 1);
        $held = ResearchNotification::where('user_id', $this->admin->id)->sole();
        $this->assertTrue($held->email_due);
        $this->assertFalse($held->in_app);
        $this->assertSame(0, Notifications::unread($this->admin));
        $this->assertSame(1, app(Notifications::class)->digests());
        Mail::assertQueued(ResearchMail::class, fn ($m) => $m->subjectLine === 'Your research notices' && $m->hasTo('admin@example.org'));
        $this->assertNotNull($held->fresh()->emailed_at);
        // Not again until a day has passed.
        $this->assertSame(0, app(Notifications::class)->digests());
    }

    public function test_a_record_rights_policy_narrows_who_may_use_its_files(): void
    {
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $fonds = app(RecordService::class)->create($this->institution, self::LEVEL.'fonds', 'Parish council records', 'en', null, $holder, 'PC');
        foreach ([$holder, $fonds] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->admin);
        }
        $policies = app(RightsPolicies::class);
        $this->assertTrue($policies->evaluate(null, $fonds, 'use')[0]);
        $this->actingAs($this->reader)->get(route('research.rights-policies', $fonds))->assertNotFound();
        $this->actingAs($this->admin)->post(route('research.rights-policies.save', $fonds), ['policy_type' => 'permission', 'action' => 'use', 'accounts' => 'Rita@example.org', 'max_uses' => 2])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('research.rights-policies.save', $fonds), ['policy_type' => 'permission', 'action' => 'use', 'accounts' => 'nobody@example.org'])
            ->assertSessionHasErrors(['research' => 'Name accounts of the institution by their e-mail addresses.']);
        $this->actingAs($this->admin)->post(route('research.rights-policies.save', $fonds), ['policy_type' => 'prohibition', 'action' => 'reproduce'])->assertSessionHasNoErrors();
        $fonds = RecordResource::find($fonds->id);

        $this->assertFalse($policies->evaluate(null, $fonds, 'use')[0]);
        $this->assertFalse($policies->evaluate($this->other, $fonds, 'use')[0]);
        $this->assertTrue($policies->evaluate($this->reader, $fonds, 'use')[0]);
        $this->assertTrue($policies->evaluate($this->reader, $fonds, 'use')[0]);
        $this->assertFalse($policies->evaluate($this->reader, $fonds, 'use')[0], 'two uses, then no more');
        $this->assertFalse($policies->evaluate($this->reader, $fonds, 'reproduce')[0]);
        $this->assertTrue($policies->evaluate($this->admin, $fonds, 'reproduce')[0], 'who may change the record is not held by them');
        $this->actingAs($this->admin)->get(route('research.rights-policies', $fonds))->assertOk()->assertSee('at most 2 uses')->assertSee('denied');
    }

    public function test_documents_print_templates_and_the_inbox(): void
    {
        $this->actingAs($this->reader)->post(route('research.documents.templates.save'), ['name' => 'X', 'fields' => 'A'])->assertForbidden();
        $this->actingAs($this->admin)->post(route('research.documents.templates.save'), ['name' => 'Consent to interview', 'document_type' => 'other',
            'fields' => "Interviewee | text | required\nDate of interview | date | required\nConditions | textarea"])->assertSessionHasNoErrors();
        $template = ResearchDocumentTemplate::sole();
        $this->assertSame(['interviewee', 'date_of_interview', 'conditions'], array_column($template->fields, 'key'));
        $this->actingAs($this->admin)->post(route('research.documents.templates.save'), ['name' => 'Bad', 'fields' => 'Field | checkbox'])->assertSessionHasErrors('research');
        $this->actingAs($this->reader)->post(route('research.documents.save', $template), ['values' => ['interviewee' => 'Mrs Botha']])->assertSessionHasErrors(['research' => 'Date of interview is needed.']);
        $this->actingAs($this->reader)->post(route('research.documents.save', $template), ['title' => 'Botha consent', 'values' => ['interviewee' => 'Mrs Botha', 'date_of_interview' => '2026-11-04']])->assertRedirect();
        $document = ResearchDocument::sole();
        $this->actingAs($this->reader)->get(route('research.documents'))->assertOk()->assertSee('Botha consent')->assertSee('Fill in Consent to interview');
        $this->actingAs($this->reader)->get(route('research.documents.edit', [$template, $document]))->assertOk()->assertSee('Mrs Botha');
        $this->actingAs($this->other)->get(route('research.documents.edit', [$template, $document]))->assertNotFound();

        $this->actingAs($this->admin)->post(route('research.print-templates.save'), ['name' => 'Short slip', 'code' => 'Short Slip', 'template_type' => 'call-slip', 'is_default' => 1, 'is_active' => 1,
            'body' => "{reference_code} - {record}\nFor {researcher} <b>{card_number}</b> {unknown}"])->assertSessionHasNoErrors();
        $this->assertSame('short-slip', ResearchPrintTemplate::sole()->code);
        $this->actingAs($this->admin)->get(route('research.print-templates'))->assertOk()->assertSee('Short slip')->assertSee('{reference_code}');
        $this->actingAs($this->reader)->get(route('research.print-templates'))->assertForbidden();
        $this->assertSame($template->institution_id, app(DocumentTemplates::class)->default($this->institution->id, 'call-slip')->institution_id);
        $this->assertSame('PC/1 - A &lt;letter&gt;<br>'."\n".'For Rita &lt;b&gt;R-1&lt;/b&gt; {unknown}',
            DocumentTemplates::fill("{reference_code} - {record}\nFor {researcher} <b>{card_number}</b> {unknown}", ['reference_code' => 'PC/1', 'record' => 'A <letter>', 'researcher' => 'Rita', 'card_number' => 'R-1']));

        $this->actingAs($this->reader)->post(route('research.projects.store'), ['title' => 'The parish bridge', 'project_type' => 'thesis', 'status' => 'active', 'visibility' => 'private']);
        $project = ResearchProject::sole();
        $this->actingAs($this->reader)->post(route('research.inbox.capture'), [])->assertSessionHasErrors(['research' => 'Capture something: a note, a web address or a file.']);
        $this->actingAs($this->reader)->post(route('research.inbox.capture'), ['source_url' => 'https://example.org/bridge-photo'])->assertSessionHasNoErrors();
        $item = ResearchInboxItem::sole();
        $this->assertSame(['clip', 'web', 'inbox'], [$item->kind->notation, $item->origin->notation, $item->status->notation]);
        $this->actingAs($this->other)->post(route('research.inbox.step', [$item, 'archive']))->assertSessionHasErrors(['research' => 'That is not yours.']);
        $this->actingAs($this->reader)->post(route('research.inbox.step', [$item, 'project']), ['project_id' => $project->id])->assertSessionHasNoErrors();
        $this->assertSame(['triaged', $project->id], [$item->fresh()->status->notation, $item->fresh()->project_id]);
        $this->actingAs($this->reader)->get(route('research.inbox', ['status' => 'triaged']))->assertOk()->assertSee('https://example.org/bridge-photo');
    }
}
