<?php

// OpenRiC Workspace - queues, service levels, overdue notices, publish gates and the diagram (roadmap phase 10b): a
// task's standing as its days pass, the daily warnings and escalation, the overdue list and its CSV, gates that block
// or warn when publishing and the override, and the requirement of an approved workflow before publishing.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\PublishGateService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Description\WorkflowService;
use App\Description\WorkflowSlaService;
use App\Mail\WorkflowTaskMail;
use App\Models\Institution;
use App\Models\PublishGateRule;
use App\Models\RecordResource;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowQueue;
use App\Models\WorkflowTask;
use App\Support\Vocab;
use App\Vocabulary\SkosImporter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WorkflowServiceLevelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $lead;

    private User $anna;

    private RecordResource $record;

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
        $this->lead = $accounts->create($institution, 'Lead archivist', 'lead@example.org', 'correct horse battery staple');
        $accounts->grantTemplateRole($this->lead, 'editor');
        $this->anna = $accounts->create($institution, 'Anna', 'anna@example.org', 'correct horse battery staple');
        $accounts->grantTemplateRole($this->anna, 'contributor');
        $holder = app(RepositoryService::class)->create($institution, 'Riverside Archives', 'en', 'RA');
        $this->record = app(RecordService::class)->create($institution, Vocab::BASE.'level-of-description/fonds', 'Papers of the Botha family', 'en', null, $holder, 'BF');
    }

    private function workflow(): Workflow
    {
        $this->actingAs($this->admin)->post('/workflow/admin', ['name' => 'Review', 'scope' => 'global', 'trigger' => 'manual', 'applies_to' => 'record', 'is_default' => '1'])->assertSessionHasNoErrors();
        $workflow = Workflow::sole();
        $this->actingAs($this->admin)->post("/workflow/admin/{$workflow->id}/steps", ['name' => 'Check', 'step_type' => 'review', 'action' => 'approve-reject'])->assertSessionHasNoErrors();

        return $workflow;
    }

    public function test_a_tasks_standing_moves_with_its_days_and_the_morning_run_warns_and_escalates_once(): void
    {
        Mail::fake();
        CarbonImmutable::setTestNow('2026-11-02 08:00:00');
        $this->workflow();
        $this->assertSame(['intake', 'qc', 'description', 'rights', 'publish', 'requests', 'movement', 'preservation'],
            app(WorkflowSlaService::class)->queues($this->admin->institution_id)->pluck('slug')->all());
        $this->actingAs($this->lead)->post("/records/{$this->record->id}/workflow")->assertSessionHasNoErrors();
        $task = WorkflowTask::sole();
        // No due date from the step: the default policy's five days.
        $this->assertSame('2026-11-07', $task->due_date->toDateString());
        $this->actingAs($this->lead)->post("/workflow/tasks/{$task->id}/claim")->assertSessionHasNoErrors();

        $sla = app(WorkflowSlaService::class);
        $standing = fn (string $at) => $sla->compute($task->fresh(), CarbonImmutable::parse($at))['status'];
        $this->assertSame(['on-track', 'at-risk', 'overdue', 'breached'], [$standing('2026-11-03 09:00'), $standing('2026-11-05 09:00'), $standing('2026-11-08 09:00'), $standing('2026-11-09 09:00')]);

        // In the description queue, its policy's ten days run from when it was made; urgent, 40% of them.
        $description = WorkflowQueue::where('slug', 'description')->sole();
        $this->actingAs($this->lead)->post("/workflow/tasks/{$task->id}/queue", ['queue' => $description->id])->assertSessionHasNoErrors();
        $task->refresh()->update(['due_date' => null]);
        $this->assertSame('2026-11-12', $sla->compute($task->fresh())['due_at']->toDateString());
        $this->actingAs($this->lead)->post("/workflow/tasks/{$task->id}/priority", ['priority' => 'urgent']);
        $this->assertSame('2026-11-06', $sla->compute($task->fresh())['due_at']->toDateString());
        $task->refresh()->update(['due_date' => '2026-11-07', 'queue_id' => null]);
        $this->actingAs($this->lead)->post("/workflow/tasks/{$task->id}/priority", ['priority' => 'normal']);

        $this->assertSame(['at_risk' => 1, 'overdue' => 0, 'escalated' => 0], $sla->process(CarbonImmutable::parse('2026-11-05 06:30')));
        $this->assertSame(['at_risk' => 0, 'overdue' => 0, 'escalated' => 0], $sla->process(CarbonImmutable::parse('2026-11-05 15:00')), 'once a day');
        $this->assertSame(['at_risk' => 0, 'overdue' => 1, 'escalated' => 0], $sla->process(CarbonImmutable::parse('2026-11-08 06:30')));
        $this->assertSame(['at_risk' => 0, 'overdue' => 0, 'escalated' => 1], $sla->process(CarbonImmutable::parse('2026-11-09 09:00')));
        $this->assertSame(['at_risk' => 0, 'overdue' => 0, 'escalated' => 0], $sla->process(CarbonImmutable::parse('2026-11-10 09:00')), 'escalated once');
        Mail::assertQueued(WorkflowTaskMail::class, fn ($m) => $m->event === 'due-soon' && $m->recipient->is($this->lead));
        Mail::assertQueued(WorkflowTaskMail::class, fn ($m) => $m->event === 'overdue' && $m->recipient->is($this->lead));
        Mail::assertQueued(WorkflowTaskMail::class, fn ($m) => $m->event === 'escalated' && $m->recipient->is($this->lead));
        $this->assertNotNull($task->fresh()->escalated_at);

        CarbonImmutable::setTestNow('2026-11-09 09:00:00');
        $this->actingAs($this->lead)->get('/workflow/overdue')->assertOk()->assertSee('Check')->assertSee('Papers of the Botha family')->assertSee('Breached');
        $csv = $this->actingAs($this->lead)->get('/workflow/overdue?format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('Check,"Papers of the Botha family",Review,,"Lead archivist",Normal,2026-11-07,2,Breached,Default', $csv);
        $this->actingAs($this->lead)->get("/workflow/tasks/{$task->id}")->assertOk()->assertSee('Breached')->assertSee('SLA Breach')->assertSee('SLA Warning')->assertSee('Escalated');
        $this->actingAs($this->lead)->get("/workflow/tasks/{$task->id}/diagram")->assertOk()->assertSee('1. Check')->assertSee('this task');
        $this->actingAs($this->lead)->get('/workflow/queues')->assertOk()->assertSee('Quality Control')->assertDontSee('Service level policies');
        CarbonImmutable::setTestNow();
    }

    public function test_an_auto_reassign_policy_gives_a_breached_task_to_its_escalation_account(): void
    {
        Mail::fake();
        CarbonImmutable::setTestNow('2026-11-02 08:00:00');
        $this->workflow();
        $sla = app(WorkflowSlaService::class);
        $intake = $sla->queues($this->admin->institution_id)->firstWhere('slug', 'intake');
        $policy = $sla->policies($this->admin->institution_id)->firstWhere('queue_id', $intake->id);
        $this->actingAs($this->admin)->post("/workflow/policies/{$policy->id}", ['name' => 'Intake queue', 'queue' => $intake->id, 'due_days' => '2', 'warning_days' => '1',
            'escalation_days' => '3', 'escalation_action' => 'auto-reassign', 'escalation_user' => $this->lead->id])->assertSessionHasNoErrors();
        $this->actingAs($this->lead)->post('/workflow/policies', ['name' => 'X', 'due_days' => '1', 'warning_days' => '0', 'escalation_days' => '1', 'escalation_action' => 'notify-lead'])->assertForbidden();
        $this->actingAs($this->admin)->post('/workflow/policies', ['name' => 'Warn too late', 'due_days' => '2', 'warning_days' => '5', 'escalation_days' => '3', 'escalation_action' => 'notify-lead'])
            ->assertSessionHasErrors('workflow');

        app(WorkflowService::class)->start($this->record, $this->lead, null, null, null, null, $intake);
        $task = WorkflowTask::sole();
        $this->assertSame('2026-11-04', $task->due_date->toDateString());
        $this->actingAs($this->anna)->post("/workflow/tasks/{$task->id}/claim")->assertSessionHasNoErrors();
        $sla->process(CarbonImmutable::parse('2026-11-05 09:00'));
        $this->assertSame([$this->lead->id, 'claimed'], [$task->fresh()->assigned_to_id, $task->fresh()->status->notation]);
        CarbonImmutable::setTestNow();
    }

    public function test_publish_gates_block_or_warn_and_who_manages_workflows_may_publish_anyway(): void
    {
        $gates = app(PublishGateService::class);
        $rules = $gates->rules($this->admin->institution_id);
        $this->assertSame([6, 0], [$rules->count(), $rules->where('is_active', true)->count()], 'PSIS\'s six rules, switched off');
        $publish = fn (User $who, array $extra = []) => $this->actingAs($who)->post("/records/{$this->record->id}/publication", ['action' => 'publish', ...$extra]);
        $publish($this->lead)->assertSessionHasNoErrors();
        $this->actingAs($this->lead)->get('/workflow/admin/gates')->assertForbidden();

        foreach (['Title required', 'Access conditions set', 'Scope and content not empty'] as $name) {
            $rule = PublishGateRule::where('name', $name)->sole();
            $this->actingAs($this->admin)->post("/workflow/admin/gates/{$rule->id}", ['name' => $rule->name, 'rule_type' => $rule->ruleType->notation, 'severity' => $rule->severity->notation,
                'field_name' => $rule->field_name, 'error_message' => $rule->error_message, 'is_active' => '1'])->assertSessionHasNoErrors();
        }
        $this->actingAs($this->admin)->post('/workflow/admin/gates', ['name' => 'Long enough', 'rule_type' => 'min-description-length', 'severity' => 'warning', 'error_message' => 'Too short'])
            ->assertSessionHasErrors('workflow');

        $publish($this->lead)->assertSessionHasErrors(['publication' => 'Not published: Access conditions must be defined before publishing.']);
        $this->actingAs($this->lead)->get("/records/{$this->record->id}/publish-readiness")->assertOk()->assertSee('Not ready: 1 blocker(s).')
            ->assertSee('Failed: Access conditions set')->assertSee('Warning: Scope and content not empty')->assertSee('Papers of the Botha family');
        // The lead may not override; the administrator may, and the audit log says so.
        $publish($this->lead, ['force' => '1'])->assertSessionHasErrors('publication');
        $publish($this->admin, ['force' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue(DB::table('audit_log')->where('after', 'like', '%publish_gates_overridden%')->exists());

        app(RecordService::class)->describe($this->record, ['conditions-governing-access' => 'Open'], 'en', $this->lead);
        $publish($this->lead)->assertSessionHasNoErrors()->assertSessionHas('status', fn ($s) => str_contains($s, 'Warnings: Scope and content should be filled in before publishing.'));

        // With an approved workflow required, publishing waits for it.
        $this->actingAs($this->admin)->post('/workflow/admin/publishing', ['workflow_required_for_publish' => '1'])->assertSessionHasNoErrors();
        app(RecordService::class)->describe($this->record, ['conditions-governing-access' => 'Open to all'], 'en', $this->lead);
        $publish($this->lead)->assertSessionHasErrors('publication');
        $this->workflow();
        $this->actingAs($this->lead)->post("/records/{$this->record->id}/workflow")->assertSessionHasNoErrors();
        $task = WorkflowTask::sole();
        $this->actingAs($this->lead)->post("/workflow/tasks/{$task->id}/claim");
        $publish($this->lead)->assertSessionHasErrors('publication');
        $this->actingAs($this->lead)->post("/workflow/tasks/{$task->id}/decide", ['decision' => 'approved'])->assertSessionHasNoErrors();
        $publish($this->lead)->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->get('/workflow/admin/'.Workflow::sole()->id.'/diagram')->assertOk()->assertSee('1. Check');
    }
}
