<?php

// OpenRiC Workspace - the file plan and its imports, captured e-mail, classification rules, declaration, vital
// records and compliance assessments (roadmap phase 13c, ADR 0015).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\ActivityService;
use App\Description\EntityDeletion;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\ComplianceAssessment;
use App\Models\DisposalClass;
use App\Models\EmailCapture;
use App\Models\FilePlanImport;
use App\Models\FilePlanNode;
use App\Models\Institution;
use App\Models\RecordDeclaration;
use App\Models\RecordDisposalClass;
use App\Models\RecordResource;
use App\Models\User;
use App\Models\VitalRecord;
use App\Relations\EntityKinds;
use App\Retention\Compliance;
use App\Retention\RetentionService;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Tests\TestCase;

class RecordsManagementTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private string $store;

    private Institution $institution;

    /** @var array<string, User> */
    private array $people = [];

    private RecordResource $fonds;

    private RecordResource $letter;

    private DisposalClass $class;

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
        foreach (['manager' => 'administrator', 'approver' => 'administrator', 'editor' => 'editor', 'reader' => null] as $who => $template) {
            $this->people[$who] = app(AccountService::class)->create($this->institution, ucfirst($who), "$who@example.org", 'correct horse battery staple');
            $template && app(AccountService::class)->grantTemplateRole($this->people[$who], $template);
        }
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $this->fonds = app(RecordService::class)->create($this->institution, self::LEVEL.'fonds', 'Parish council records', 'en', null, $holder, 'PC');
        $this->letter = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Letter about the parish bridge', 'en', $this->fonds, null, 'PC/1');
        foreach ([$holder, $this->fonds, $this->letter] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->people['manager']);
        }
        $retention = app(RetentionService::class);
        $schedule = $retention->saveSchedule($this->institution, ['reference' => 'RS-1', 'title' => 'Council records', 'authority' => 'NARSSA Standing Disposal Authority PAR 1'], null, $this->people['manager']);
        $this->class = $retention->saveClass($schedule, ['reference' => '2.1', 'title' => 'Correspondence', 'retention_years' => 5, 'trigger' => 'creation-end', 'action' => 'destroy',
            'review_required' => true], null, $this->people['manager']);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->store)) {
            exec('rm -rf '.escapeshellarg($this->store));
        }
        parent::tearDown();
    }

    private function node(string $code): FilePlanNode
    {
        return FilePlanNode::where(['institution_id' => $this->institution->id, 'code' => $code])->sole();
    }

    public function test_a_record_is_placed_in_one_node_and_exported_as_documenting_its_function(): void
    {
        $m = $this->people['manager'];
        app(RetentionService::class)->approve($this->class->schedule, 'Records Manager', $m);
        $this->actingAs($this->people['reader'])->get(route('records-management.file-plan'))->assertForbidden();
        $function = app(ActivityService::class)->create($this->institution, 'Maintaining public works', 'en');
        app(ActivityService::class)->updateFunction($function, true, $m);
        app(PublicationService::class)->apply($function, 'publish', null, false, $m);
        $this->actingAs($m)->post(route('records-management.nodes.store'), ['code' => 'PW', 'title' => 'Public works', 'node_type' => 'function', 'activity_id' => $function->id])->assertSessionHasNoErrors();
        $this->actingAs($m)->post(route('records-management.nodes.store'), ['code' => 'PW/2', 'title' => 'Bridges', 'node_type' => 'series', 'parent_id' => $this->node('PW')->id,
            'disposal_class_id' => $this->class->id])->assertSessionHasNoErrors();
        $this->actingAs($m)->post(route('records-management.nodes.store'), ['code' => 'PW', 'title' => 'Again'])->assertSessionHasErrors(['records' => 'Another node has the code PW.']);
        $this->actingAs($m)->post(route('records-management.nodes.update', $this->node('PW')), ['code' => 'PW', 'title' => 'Public works', 'parent_id' => $this->node('PW/2')->id])
            ->assertSessionHasErrors(['records' => 'A node cannot be placed inside itself.']);

        $this->actingAs($m)->post(route('records.records-management', [$this->letter, 'place']), ['file_plan_node_id' => $this->node('PW/2')->id])->assertSessionHasNoErrors();
        // The node's disposal class went to the record, which had none.
        $this->assertSame($this->class->id, RecordDisposalClass::where('record_resource_id', $this->letter->id)->value('disposal_class_id'));
        $this->actingAs($m)->get(route('records-management.node', $this->node('PW/2')))->assertOk()->assertSee('Letter about the parish bridge');
        $this->actingAs($m)->post(route('records-management.nodes.remove', $this->node('PW/2')))->assertSessionHasErrors(['records' => 'Move its nodes and records first.']);
        // Only a node that is a function or activity is exported, as what the record documents.
        $this->actingAs($m)->post(route('records-management.nodes.update', $this->node('PW/2')), ['code' => 'PW/2', 'title' => 'Bridges', 'node_type' => 'series', 'parent_id' => $this->node('PW')->id,
            'activity_id' => $function->id, 'disposal_class_id' => $this->class->id])->assertSessionHasNoErrors();
        auth()->logout();
        $doc = $this->getJson('/api/ric/v1/records/'.$this->letter->id)->assertOk()->json();
        $this->assertSame([$function->iri], array_column($doc['rico:documents'], '@id'));
        $this->actingAs($m)->get(route('records.show', $this->letter))->assertOk()->assertSee('PW/2 Bridges');
    }

    public function test_file_plans_are_imported_from_a_spreadsheet_a_listing_and_ead(): void
    {
        $m = $this->people['manager'];
        $csv = "Reference,Title,Retention,Disposal action\nPC,Parish council,,\nPC/1,Correspondence,5 years,Destroy\nPC/1,Twice,,\nPC/9/1,Orphan,,\nPC/2,Minutes,Permanent,Transfer to archives\n";
        $this->actingAs($m)->post(route('records-management.import'), ['source' => 'spreadsheet', 'file' => UploadedFile::fake()->createWithContent('plan.csv', $csv), 'department' => 'Clerk', 'place_records' => 1])
            ->assertSessionHas('status', '2 of 5 nodes imported; 2 records placed.');
        // A code given twice is reported for each row, not settled by taking one.
        $this->assertSame([['PC', null], ['PC/2', 'PC']], FilePlanNode::with('parent')->orderBy('code')->get()->map(fn ($n) => [$n->code, $n->parent?->code])->all());
        $this->assertSame(['Permanent', 'transfer'], [$this->node('PC/2')->retention_period, $this->node('PC/2')->disposalAction->notation]);
        $this->assertSame('PC', $this->letter->filePlanPlacement->node->code, 'placed by its reference code');
        $import = FilePlanImport::sole();
        $this->assertSame(['partial', 3], [$import->status->notation, count($import->errors)]);
        $this->assertContains('PC/1 appears more than once.', $import->errors);

        $this->actingAs($m)->post(route('records-management.import'), ['source' => 'directory', 'file' => UploadedFile::fake()->createWithContent('folders.txt', "Finance/Budgets/2026\nFinance\\Audits\n")])
            ->assertSessionHasNoErrors();
        $this->assertSame('Finance/Budgets', $this->node('Finance/Budgets/2026')->parent->code);
        $ead = '<ead xmlns="urn:isbn:1-931666-22-9"><archdesc level="fonds"><dsc><c01><did><unitid>HR</unitid><unittitle>Human resources</unittitle></did><c02><did><unitid>HR/1</unitid><unittitle>Staff files</unittitle></did></c02></c01></dsc></archdesc></ead>';
        $this->actingAs($m)->post(route('records-management.import'), ['source' => 'xml', 'file' => UploadedFile::fake()->createWithContent('plan.xml', $ead)])->assertSessionHasNoErrors();
        $this->assertSame('HR', $this->node('HR/1')->parent->code);
        $this->actingAs($m)->post(route('records-management.import'), ['source' => 'xml', 'file' => UploadedFile::fake()->createWithContent('bad.xml', '<not closed')])->assertSessionHasErrors('records');
    }

    public function test_e_mail_is_captured_once_classified_by_a_rule_and_declared_a_record_with_its_original(): void
    {
        $m = $this->people['manager'];
        app(RetentionService::class)->approve($this->class->schedule, 'Records Manager', $m);
        $this->actingAs($m)->post(route('records-management.nodes.store'), ['code' => 'PC', 'title' => 'Parish council', 'node_type' => 'function', 'disposal_class_id' => $this->class->id]);
        $this->actingAs($m)->post(route('records-management.rules.save'), ['name' => 'Clerk mail', 'rule_type' => 'department', 'match_pattern' => 'parish.example.org',
            'file_plan_node_id' => $this->node('PC')->id, 'apply_on' => 'capture', 'priority' => 5, 'is_active' => 1])->assertSessionHasNoErrors();
        $this->actingAs($m)->post(route('records-management.rules.save'), ['name' => 'Bad', 'rule_type' => 'folder-path', 'match_pattern' => '([unclosed', 'file_plan_node_id' => $this->node('PC')->id])
            ->assertSessionHasErrors(['records' => 'That is not a regular expression the Workspace can use.']);

        $eml = UploadedFile::fake()->createWithContent('minutes.eml', file_get_contents(base_path('tests/Fixtures/mail/minutes.eml')));
        $this->actingAs($m)->post(route('records-management.emails.capture'), ['files' => [$eml, UploadedFile::fake()->createWithContent('quote.msg', file_get_contents(base_path('tests/Fixtures/mail/quote.msg')))]])
            ->assertSessionHas('status', '2 messages captured.');
        $this->actingAs($m)->post(route('records-management.emails.capture'), ['files' => [UploadedFile::fake()->createWithContent('again.eml', file_get_contents(base_path('tests/Fixtures/mail/minutes.eml')))]])
            ->assertSessionHasErrors(['records' => 'again.eml: That message is captured already.']);
        $minutes = EmailCapture::where('message_id', 'minutes-1902@parish.example.org')->sole();
        $this->assertSame(['Minutes of the bridge committee, October', 'classified', 'PC', 1, 'eml-upload'], [$minutes->subject, $minutes->status->notation, $minutes->node->code, $minutes->attachment_count, $minutes->source->notation]);
        $this->assertStringContainsString('Café costs excluded', $minutes->body_text);
        $quote = EmailCapture::where('message_id', 'quote-1902@parish.example.org')->sole();
        $this->assertSame(['Parish Clerk <clerk@parish.example.org>', 'Bridge repairs, quote attached', 'msg-upload', 'quote.txt', '2026-10-01 08:30:00'],
            [$quote->from_address, $quote->subject, $quote->source->notation, $quote->attachments[0]['name'], $quote->sent_at->format('Y-m-d H:i:s')]);
        $this->assertSame(hash_file('sha256', base_path('tests/Fixtures/mail/quote.msg')), $quote->sha256);
        $this->actingAs($m)->get(route('records-management.email.original', $quote))->assertOk();

        $this->actingAs($m)->post(route('records-management.email.step', [$minutes, 'declare']), ['parent_iri' => $this->fonds->iri, 'language' => 'en'])->assertRedirect();
        $record = $minutes->fresh()->record;
        $this->assertSame(['Minutes of the bridge committee, October', $this->fonds->id, 'item', 'PC'], [EntityKinds::label($record), $record->parent_id, $record->level->notation, $record->filePlanPlacement->node->code]);
        $this->assertSame('2026-10-01', $record->dates()->sole()->normalized_value);
        $this->assertSame($minutes->sha256, $record->instantiations()->sole()->files()->first()->sha256);
        $this->assertStringContainsString('From: "Parish Clerk" <clerk@parish.example.org>', (string) $record->described('scope-and-content', ['en']));
        $this->assertSame('declared', RecordDeclaration::where('record_resource_id', $record->id)->sole()->status->notation);
        $this->assertSame($this->class->id, RecordDisposalClass::where('record_resource_id', $record->id)->value('disposal_class_id'));
        $this->actingAs($m)->post(route('records-management.email.step', [$minutes->fresh(), 'declare']))->assertSessionHasErrors(['records' => 'It is declared already.']);
    }

    public function test_a_declared_record_cannot_be_deleted_outside_its_disposal_and_vital_records_are_reviewed(): void
    {
        $m = $this->people['manager'];
        $this->actingAs($m)->post(route('records-management.nodes.store'), ['code' => 'PC', 'title' => 'Parish council']);
        $this->actingAs($m)->post(route('records-management.rules.save'), ['name' => 'Bridges', 'rule_type' => 'folder-path', 'match_pattern' => '^/Parish council records/', 'file_plan_node_id' => $this->node('PC')->id,
            'apply_on' => 'declare', 'is_active' => 1])->assertSessionHasNoErrors();
        $this->actingAs($this->people['reader'])->post(route('records.records-management', [$this->letter, 'declare']))->assertForbidden();
        $this->actingAs($m)->post(route('records.records-management', [$this->letter, 'declare']), ['notes' => 'Final letter'])->assertSessionHasNoErrors();
        $this->actingAs($m)->post(route('records.records-management', [$this->letter, 'declare']))->assertSessionHasErrors(['records' => 'Its declaration awaits approval.']);
        $declaration = RecordDeclaration::sole();
        $this->actingAs($m)->get(route('records-management.declarations'))->assertOk()->assertSee('Letter about the parish bridge');
        $this->actingAs($this->people['approver'])->post(route('records-management.declarations.step', [$declaration, 'approve']))->assertSessionHasNoErrors();
        $this->assertSame(['declared', 'PC'], [$declaration->fresh()->status->notation, $this->letter->fresh()->filePlanPlacement->node->code], 'the declaration rule placed it');

        try {
            app(EntityDeletion::class)->delete($this->letter->fresh(), $m, 'tidying up');
            $this->fail('a declared record was deleted');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('It is declared a record; only its disposal, once cleared, may remove it.', $e->getMessage());
        }

        $this->actingAs($m)->post(route('records.records-management', [$this->fonds, 'vital']), ['reason' => 'Proves the council owns the bridge', 'review_cycle_days' => 30])->assertSessionHasNoErrors();
        $vital = VitalRecord::sole();
        $this->assertSame('2026-12-05', $vital->next_review_on->toDateString());
        $this->travelTo('2026-12-10 09:00');
        $this->actingAs($m)->get(route('records-management.declarations'))->assertOk()->assertSee('overdue');
        $this->actingAs($m)->post(route('records-management.vital.step', [$vital, 'review']))->assertSessionHasNoErrors();
        $this->assertSame('2027-01-09', $vital->fresh()->next_review_on->toDateString());
    }

    public function test_a_compliance_assessment_reads_the_institutions_own_records_management_and_is_final_once_signed_off(): void
    {
        $m = $this->people['manager'];
        $this->actingAs($m)->post(route('records-management.compliance.store'), ['framework' => 'iso-15489', 'reference' => 'CA-1', 'title' => 'First assessment'])->assertRedirect();
        $first = ComplianceAssessment::sole();
        $status = fn ($a) => collect($a->findings)->pluck('status', 'ref')->all();
        $this->assertSame(['15489-5' => 'fail', '15489-6' => 'fail', '15489-7' => 'pass', '15489-8' => 'fail', '15489-9' => 'pass', '15489-10' => 'pass'], $status($first));

        app(RetentionService::class)->approve($this->class->schedule, 'Records Manager', $m);
        $this->actingAs($m)->post(route('records-management.nodes.store'), ['code' => 'PC', 'title' => 'Parish council']);
        $this->actingAs($m)->post(route('records.records-management', [$this->letter, 'place']), ['file_plan_node_id' => $this->node('PC')->id]);
        $this->actingAs($m)->post(route('records.records-management', [$this->letter, 'declare']));
        $this->actingAs($this->people['approver'])->post(route('records-management.declarations.step', [RecordDeclaration::sole(), 'approve']));
        $this->actingAs($m)->post(route('records-management.assessment.step', [$first, 'run']))->assertSessionHasNoErrors();
        $this->assertSame(['pass', 'pass', 'pass'], array_values(array_intersect_key($status($first->fresh()), array_flip(['15489-5', '15489-6', '15489-8']))));
        $this->assertSame(100, Compliance::percent($first->fresh()));
        $this->actingAs($m)->get(route('records-management.assessment', $first))->assertOk()->assertSee('An approved retention schedule');

        $this->actingAs($m)->post(route('records-management.assessment.step', [$first, 'sign-off']), ['signed_off_by' => 'Dr N. Dlamini'])->assertSessionHasNoErrors();
        $this->actingAs($m)->post(route('records-management.assessment.step', [$first, 'run']))->assertSessionHasErrors(['records' => 'It is signed off; it no longer changes.']);
        $this->actingAs($m)->post(route('records-management.compliance.store'), ['framework' => 'dod-5015-2', 'reference' => 'CA-2', 'title' => 'DoD'])->assertRedirect();
        $this->assertSame('warn', $status(ComplianceAssessment::where('reference', 'CA-2')->sole())['DoD-C2.3'], 'no legal holds, said so');
    }
}
