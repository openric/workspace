<?php

// OpenRiC Workspace - work assignment and the description workflow (roadmap phase 10a): a team divides a fonds'
// description among its members and sees what is left; the pool, claiming, deciding, returning and resubmitting, who
// may do what, bulk operations, notifications, and the workflows and their steps.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Description\WorkflowService;
use App\Mail\WorkflowTaskMail;
use App\Models\Identifier;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowTask;
use App\Support\Vocab;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $lead;

    private User $anna;

    private User $ben;

    private RecordResource $fonds;

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
        $this->ben = $accounts->create($institution, 'Ben', 'ben@example.org', 'correct horse battery staple');
        $accounts->grantTemplateRole($this->ben, 'contributor');
        $holder = app(RepositoryService::class)->create($institution, 'Riverside Archives', 'en', 'RA');
        $records = app(RecordService::class);
        $this->fonds = $records->create($institution, Vocab::BASE.'level-of-description/fonds', 'Papers of the Botha family', 'en', null, $holder, 'BF');
        foreach (['Correspondence', 'Diaries', 'Photographs'] as $i => $title) {
            $records->create($institution, Vocab::BASE.'level-of-description/series', $title, 'en', $this->fonds, $holder, 'BF/'.($i + 1));
        }
    }

    private function unit(string $reference): RecordResource
    {
        return RecordResource::whereIn('id', Identifier::where(['subject_table' => 'record_resources', 'value' => $reference])->select('subject_id'))->sole();
    }

    /** The institution's description workflow: describe (any contributor, from the pool, with a checklist), then a review only editors take. */
    private function workflow(): Workflow
    {
        $this->actingAs($this->admin)->post('/workflow/admin', ['name' => 'Description', 'scope' => 'global', 'trigger' => 'manual', 'applies_to' => 'record', 'is_default' => '1'])
            ->assertSessionHasNoErrors();
        $workflow = Workflow::sole();
        $editors = app(RoleService::class)->byTemplate(Institution::firstOrFail(), 'editor');
        $this->actingAs($this->admin)->post("/workflow/admin/{$workflow->id}/steps", ['name' => 'Describe', 'step_type' => 'describe', 'action' => 'approve-reject',
            'checklist' => "Scope and content written\nDates given", 'escalation_days' => '14'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post("/workflow/admin/{$workflow->id}/steps", ['name' => 'Review', 'step_type' => 'review', 'action' => 'approve-reject',
            'allowed_roles' => [$editors->id]])->assertSessionHasNoErrors();

        return $workflow->fresh();
    }

    public function test_a_team_divides_a_fonds_description_among_its_members_and_sees_what_is_left(): void
    {
        Mail::fake();
        $this->workflow();
        $this->actingAs($this->lead)->post("/records/{$this->fonds->id}/workflow", ['inside' => '1'])
            ->assertSessionHas('status', 'Workflow started for 4; 0 skipped (in a workflow already, or not yours to change).');
        $tasks = WorkflowTask::with('step')->get();
        $this->assertSame([4, ['Describe'], [now()->addDays(14)->toDateString()]], [$tasks->count(), $tasks->pluck('step.name')->unique()->values()->all(),
            $tasks->map(fn ($t) => $t->due_date->toDateString())->unique()->values()->all()]);

        // The lead divides it: two units each.
        [$first, $second, $third, $fourth] = $tasks->sortBy(fn ($t) => $t->subject()->identifier ?? '')->values()->all();
        $this->actingAs($this->lead)->post('/workflow/bulk', ['tasks' => [$first->id, $second->id], 'operation' => 'assign', 'assignee' => $this->anna->id])->assertSessionHas('status', '2 done, 0 not done.');
        $this->actingAs($this->lead)->post('/workflow/bulk', ['tasks' => [$third->id, $fourth->id], 'operation' => 'assign', 'assignee' => $this->ben->id, 'comment' => 'Start with these'])->assertRedirect();
        Mail::assertQueued(WorkflowTaskMail::class, fn ($m) => $m->event === 'assigned' && $m->recipient->is($this->ben) && $m->comment === 'Start with these');
        $this->actingAs($this->anna)->get('/workflow/my-tasks')->assertOk()->assertSee('Papers of the Botha family')->assertSee('Correspondence')->assertDontSee('Photographs');

        // Anna describes and finishes one; the checklist must be ticked first.
        $this->actingAs($this->anna)->post("/workflow/tasks/{$first->id}/decide", ['decision' => 'approved'])->assertSessionHasErrors('workflow');
        $this->actingAs($this->anna)->post("/workflow/tasks/{$first->id}/decide", ['decision' => 'approved', 'checklist' => ['Scope and content written', 'Dates given']])->assertSessionHasNoErrors();
        $review = WorkflowTask::where('previous_task_id', $first->id)->sole();
        $this->assertSame(['Review', 'pending', null], [$review->step->name, $review->status->notation, $review->assigned_to_id]);

        // What is left, on the fonds and in team work.
        $this->actingAs($this->lead)->get("/records/{$this->fonds->id}")->assertOk()->assertSee('4 tasks open; 0 of 4 units through their workflow.')
            ->assertSee('Anna: 1')->assertSee('Ben: 2')->assertSee('Nobody (in the pool): 1');
        $this->actingAs($this->lead)->get('/workflow/team')->assertOk()->assertSee('Open tasks')->assertSee('Anna: 1')->assertSee('Ben: 2');

        // The review is an editor's: Anna may not take it, the lead may, and approving it completes the workflow.
        $this->actingAs($this->anna)->post("/workflow/tasks/{$review->id}/claim")->assertSessionHasErrors('workflow');
        $this->actingAs($this->lead)->get('/workflow/pool')->assertOk()->assertSee('Review');
        $this->actingAs($this->lead)->post("/workflow/tasks/{$review->id}/claim")->assertSessionHasNoErrors();
        $this->actingAs($this->lead)->post("/workflow/tasks/{$review->id}/decide", ['decision' => 'approved'])->assertSessionHasNoErrors();
        Mail::assertQueued(WorkflowTaskMail::class, fn ($m) => $m->event === 'completed' && $m->recipient->is($this->lead));
        $this->actingAs($this->lead)->get("/records/{$this->fonds->id}")->assertOk()->assertSee('3 tasks open; 1 of 4 units through their workflow.');
    }

    public function test_return_resubmit_reject_and_who_may_decide(): void
    {
        Mail::fake();
        $this->workflow();
        $unit = $this->unit('BF/2');
        $this->actingAs($this->lead)->post("/records/{$unit->id}/workflow")->assertSessionHasNoErrors();
        $this->actingAs($this->lead)->post("/records/{$unit->id}/workflow")->assertSessionHasErrors('workflow');
        $task = WorkflowTask::sole();

        $this->actingAs($this->anna)->post("/workflow/tasks/{$task->id}/claim")->assertSessionHasNoErrors();
        $this->actingAs($this->ben)->post("/workflow/tasks/{$task->id}/claim")->assertSessionHasErrors('workflow');
        $this->actingAs($this->ben)->post("/workflow/tasks/{$task->id}/decide", ['decision' => 'approved', 'checklist' => ['Scope and content written', 'Dates given']])->assertSessionHasErrors('workflow');
        $this->actingAs($this->anna)->post("/workflow/tasks/{$task->id}/begin")->assertSessionHasNoErrors();
        $this->actingAs($this->anna)->post("/workflow/tasks/{$task->id}/decide", ['decision' => 'returned'])->assertSessionHasErrors('workflow');
        $this->actingAs($this->anna)->post("/workflow/tasks/{$task->id}/decide", ['decision' => 'returned', 'comment' => 'The dates are missing'])->assertSessionHasNoErrors();
        Mail::assertQueued(WorkflowTaskMail::class, fn ($m) => $m->event === 'returned' && $m->recipient->is($this->lead) && $m->comment === 'The dates are missing');
        $this->assertSame(['returned', 1], [$task->fresh()->status->notation, $task->fresh()->retry_count]);

        // Only whoever submitted it resubmits it, to the pool.
        $this->actingAs($this->anna)->post("/workflow/tasks/{$task->id}/resubmit")->assertSessionHasErrors('workflow');
        $this->actingAs($this->lead)->post("/workflow/tasks/{$task->id}/resubmit", ['comment' => 'Dates added'])->assertSessionHasNoErrors();
        $this->assertSame(['pending', null], [$task->fresh()->status->notation, $task->fresh()->assigned_to_id]);
        $this->actingAs($this->ben)->post("/workflow/tasks/{$task->id}/claim")->assertSessionHasNoErrors();
        $this->actingAs($this->ben)->post("/workflow/tasks/{$task->id}/release")->assertSessionHasNoErrors();
        $this->actingAs($this->ben)->post("/workflow/tasks/{$task->id}/claim")->assertSessionHasNoErrors();
        $this->actingAs($this->ben)->post("/workflow/tasks/{$task->id}/priority", ['priority' => 'urgent'])->assertSessionHasNoErrors();
        $this->actingAs($this->ben)->post("/workflow/tasks/{$task->id}/note", ['comment' => 'Checking the diaries for dates'])->assertSessionHasNoErrors();
        $this->actingAs($this->ben)->post("/workflow/tasks/{$task->id}/decide", ['decision' => 'rejected', 'comment' => 'Out of scope'])->assertSessionHasNoErrors();
        $this->assertSame(['rejected', 'rejected'], [$task->fresh()->status->notation, $task->fresh()->decision->notation]);

        $this->actingAs($this->lead)->get("/workflow/tasks/{$task->id}")->assertOk()->assertSeeInOrder(['Rejected', 'Out of scope', 'Note Added', 'Checking the diaries for dates',
            'Priority Changed', 'Normal to Urgent', 'Claimed', 'Released', 'Claimed', 'Resubmitted', 'Dates added', 'Returned', 'The dates are missing', 'Claimed', 'Workflow started']);
        $this->actingAs($this->lead)->get("/records/{$unit->id}")->assertOk()->assertSee('Workflow history');
        // Rejected, it is no longer in a workflow, so it may start again.
        $this->actingAs($this->lead)->post("/records/{$unit->id}/workflow")->assertSessionHasNoErrors();
        // A task of a record the account may not change is not its to take: a translator may not.
        $translator = app(AccountService::class)->create(Institution::firstOrFail(), 'Translator', 'translator@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($translator, 'translator');
        $this->actingAs($translator)->post('/workflow/tasks/'.WorkflowTask::latest('created_at')->first()->id.'/claim')->assertSessionHasErrors('workflow');
    }

    public function test_workflows_and_steps_are_managed_by_who_may_and_cancel_is_theirs(): void
    {
        $workflow = $this->workflow();
        $this->actingAs($this->lead)->get('/workflow/admin')->assertForbidden();
        $this->actingAs($this->lead)->post('/workflow/admin', ['name' => 'X', 'scope' => 'global', 'trigger' => 'manual', 'applies_to' => 'record'])->assertForbidden();
        $this->actingAs($this->admin)->get('/workflow/admin')->assertOk()->assertSee('Description')->assertSee('2 steps');
        $this->actingAs($this->admin)->get("/workflow/admin/{$workflow->id}")->assertOk()->assertSeeInOrder(['1. Describe', '2. Review']);
        [$describe, $review] = $workflow->steps->all();
        $this->actingAs($this->admin)->post("/workflow/admin/{$workflow->id}/steps/{$review->id}/move", ['direction' => 'earlier'])->assertRedirect();
        $this->assertSame(['Review', 'Describe'], $workflow->steps()->pluck('name')->all());
        $this->actingAs($this->admin)->post('/workflow/admin', ['name' => 'Collection review', 'scope' => 'collection', 'trigger' => 'submit', 'applies_to' => 'record'])->assertSessionHasErrors('workflow');
        $this->actingAs($this->admin)->post('/workflow/admin', ['name' => 'Collection review', 'scope' => 'collection', 'record' => $this->fonds->id, 'trigger' => 'submit', 'applies_to' => 'record'])
            ->assertSessionHasNoErrors();
        $collection = Workflow::where('name', 'Collection review')->sole();
        $this->actingAs($this->admin)->post("/workflow/admin/{$collection->id}/steps", ['name' => 'Check', 'step_type' => 'quality-check', 'action' => 'acknowledge'])->assertSessionHasNoErrors();
        // Inside the fonds, its own workflow applies; elsewhere the default.
        $unit = $this->unit('BF/3');
        $this->assertSame('Collection review', app(WorkflowService::class)->applicable($unit)->name);

        $this->actingAs($this->lead)->post("/records/{$unit->id}/workflow")->assertSessionHasNoErrors();
        $task = WorkflowTask::sole();
        $this->actingAs($this->admin)->post("/workflow/admin/{$collection->id}/steps/{$task->step_id}/remove")->assertSessionHasErrors('workflow');
        $this->actingAs($this->admin)->post("/workflow/admin/{$collection->id}/remove")->assertSessionHasErrors('workflow');
        $this->actingAs($this->lead)->post("/workflow/tasks/{$task->id}/cancel")->assertSessionHasErrors('workflow');
        $this->actingAs($this->admin)->post("/workflow/tasks/{$task->id}/cancel", ['comment' => 'Started by mistake'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->get('/workflow')->assertOk()->assertSee('Cancelled')->assertSee('Started by mistake');
        $this->actingAs($this->admin)->post("/workflow/admin/{$collection->id}/remove")->assertRedirect('/workflow/admin');
        $this->assertNull(Workflow::find($collection->id));
    }
}
