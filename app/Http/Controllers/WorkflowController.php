<?php

// OpenRiC Workspace - the workflow: the dashboard, my tasks, the pool, team work and history, a task and what is done
// to it, bulk operations, starting a workflow for a record (and everything inside it), and the workflows and their
// steps (Heratio's ahg-workflow WorkflowController and PSIS's ahgWorkflowPlugin; roadmap phase 10a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Every signed-in account sees its own tasks; working a task needs update on its record, and what the step asks.
// Workflows and steps need Manage workflows, as Heratio's admin pages do.

namespace App\Http\Controllers;

use App\Description\WorkflowService;
use App\Description\WorkflowSlaService;
use App\Http\Controllers\Concerns\ResolvesDescribedEntity;
use App\Models\Concept;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\Role;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowHistory;
use App\Models\WorkflowStep;
use App\Models\WorkflowTask;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class WorkflowController extends Controller
{
    use ResolvesDescribedEntity;

    public function __construct(private WorkflowService $workflows) {}

    /** Heratio's dashboard: the counts, my tasks, the pool and what was done lately. */
    public function dashboard(Request $request): View
    {
        $user = $request->user();

        return view('workflow.dashboard', ['stats' => $this->workflows->stats($user), 'mine' => $this->workflows->mine($user)->take(10),
            'pool' => $this->workflows->pool($user)->take(10), 'recent' => $this->recent($user->institution_id, 20)]);
    }

    public function mine(Request $request): View
    {
        $status = (string) $request->query('status', '');

        return view('workflow.list', ['title' => __('My tasks'), 'tasks' => $this->workflows->mine($request->user(), $status === '' ? null : $status),
            'status' => $status, 'statuses' => $this->choices('workflow-task-status'), 'bulk' => true] + $this->bulkChoices($request->user()));
    }

    public function pool(Request $request): View
    {
        return view('workflow.list', ['title' => __('Pool'), 'tasks' => $this->workflows->pool($request->user()), 'pool' => true,
            'bulk' => $this->workflows->manages($request->user())] + $this->bulkChoices($request->user()));
    }

    /** PSIS's team work: the open tasks of a role's members, or of everyone, with who has how many. */
    public function team(Request $request): View
    {
        $user = $request->user();
        $role = ($key = (string) $request->query('role', '')) === '' ? null : Role::where('institution_id', $user->institution_id)->findOrFail($key);
        $tasks = $this->workflows->team($user->institution_id, $role);

        return view('workflow.list', ['title' => __('Team work'), 'tasks' => $tasks, 'team' => true, 'role' => $role,
            'roles' => Role::where('institution_id', $user->institution_id)->with('labels', 'template.labels')->get()->sortBy(fn (Role $r) => $r->name())->values(),
            'byAssignee' => $tasks->groupBy(fn ($t) => $t->assignedTo?->name ?? __('Nobody (in the pool)'))->map->count()->sortKeys(),
            'bulk' => $this->workflows->manages($user)] + $this->bulkChoices($user));
    }

    public function history(Request $request): View
    {
        return view('workflow.history', ['events' => $this->recent($request->user()->institution_id, 200)]);
    }

    public function show(Request $request, WorkflowTask $task): View
    {
        $user = $request->user();
        $this->own($request, $task);
        $task->load('queue', 'workflow', 'step.stepType.labels', 'step.action.labels', 'status.labels', 'priority.labels', 'decision.labels', 'assignedTo', 'submittedBy', 'decisionBy',
            'history.action.labels', 'history.performedBy', 'history.toStatus.labels', 'history.fromStatus.labels');
        $subject = $task->subject();

        return view('workflow.task', ['task' => $task, 'subject' => $subject, 'title' => $subject ? $this->workflows->title($subject) : null,
            'mayWork' => $this->workflows->mayWork($task, $user), 'eligible' => $this->workflows->eligible($task, $user), 'manages' => $this->workflows->manages($user),
            'priorities' => $this->choices('workflow-priority'), 'queues' => app(WorkflowSlaService::class)->queues($task->institution_id, true),
            'sla' => app(WorkflowSlaService::class)->compute($task),
            'assignees' => User::where('institution_id', $task->institution_id)->orderBy('name')->get()->filter(fn (User $u) => $this->workflows->eligible($task, $u))->values()]);
    }

    /** Claim, release, begin, assign, decide, resubmit, cancel, note, priority or due date. */
    public function act(Request $request, WorkflowTask $task, string $action): RedirectResponse
    {
        $user = $request->user();
        $this->own($request, $task);
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:5000'], 'decision' => ['nullable', 'in:approved,rejected,returned'],
            'assignee' => ['nullable', 'uuid'], 'priority' => ['nullable', 'string', 'max:64'], 'due_date' => ['nullable', 'string', 'max:10'],
            'checklist' => ['nullable', 'array'], 'checklist.*' => ['string', 'max:500']]);
        $comment = $data['comment'] ?? null;
        try {
            match ($action) {
                'claim' => $this->workflows->claim($task, $user),
                'release' => $this->workflows->release($task, $user, $comment),
                'begin' => $this->workflows->begin($task, $user),
                'assign' => $this->workflows->assign($task, User::where('institution_id', $task->institution_id)->findOrFail($data['assignee'] ?? null), $user, $comment),
                'decide' => $this->workflows->decide($task, (string) ($data['decision'] ?? ''), $user, $comment, $data['checklist'] ?? []),
                'resubmit' => $this->workflows->resubmit($task, $user, $comment),
                'cancel' => $this->workflows->cancel($task, $user, $comment),
                'note' => $this->workflows->note($task, (string) $comment, $user),
                'priority' => $this->workflows->prioritise($task, (string) ($data['priority'] ?? ''), $user),
                'due' => $this->workflows->setDueDate($task, $data['due_date'] ?? null, $user, $comment),
            };
        } catch (InvalidArgumentException $e) {
            return redirect()->route('workflow.tasks.show', $task)->withErrors(['workflow' => ucfirst($e->getMessage()).'.']);
        }

        return redirect()->route('workflow.tasks.show', $task)->with('status', __('Saved.'));
    }

    /** Heratio's bulk operations on the tasks chosen. */
    public function bulk(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate(['tasks' => ['required', 'array', 'min:1', 'max:500'], 'tasks.*' => ['uuid'], 'operation' => ['required', 'in:'.implode(',', WorkflowService::BULK)],
            'assignee' => ['nullable', 'uuid'], 'priority' => ['nullable', 'string', 'max:64'], 'queue' => ['nullable', 'uuid'], 'note' => ['nullable', 'string', 'max:5000'], 'comment' => ['nullable', 'string', 'max:5000']]);
        try {
            $result = $this->workflows->bulk(WorkflowTask::where('institution_id', $user->institution_id)->whereIn('id', $data['tasks'])->get(), $data['operation'], $data, $user);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['workflow' => ucfirst($e->getMessage()).'.']);
        }

        return back()->with('status', __(':done done, :failed not done.', ['done' => $result['done'], 'failed' => count($result['failed'])]))
            ->with('failed', $result['failed']);
    }

    /** Start a workflow for an entity; for a description, with everything inside it if asked. */
    public function start(Request $request, string $kind, string $id): RedirectResponse
    {
        [$subject, $route] = $this->subject($request, $kind, $id);
        $data = $request->validate(['workflow' => ['nullable', 'uuid'], 'inside' => ['nullable', 'boolean'], 'priority' => ['nullable', 'string', 'max:64'],
            'due_date' => ['nullable', 'string', 'max:10']]);
        $workflow = ($data['workflow'] ?? null) ? Workflow::where('institution_id', $request->user()->institution_id)->findOrFail($data['workflow']) : null;
        try {
            if ($subject instanceof RecordResource) {
                $result = $this->workflows->startTree($subject, $request->user(), $workflow, (bool) ($data['inside'] ?? false), $data['priority'] ?? null, $data['due_date'] ?? null);
                $status = __('Workflow started for :started; :skipped skipped (in a workflow already, or not yours to change).', $result);
            } else {
                $this->workflows->start($subject, $request->user(), $workflow, $data['priority'] ?? null, $data['due_date'] ?? null);
                $status = __('Workflow started.');
            }
        } catch (InvalidArgumentException $e) {
            return redirect()->to(route($route, $subject).'#workflow')->withErrors(['workflow' => ucfirst($e->getMessage()).'.']);
        }

        return redirect()->to(route($route, $subject).'#workflow')->with('status', $status);
    }

    // Workflows and steps (Manage workflows)

    public function index(Request $request): View
    {
        $this->manage($request);

        return view('workflow.admin', ['workflows' => Workflow::where('institution_id', $request->user()->institution_id)
            ->with('scope.labels', 'trigger.labels', 'scopeRepository.names.nameType', 'scopeRecord.names.nameType')->withCount('steps')
            ->withCount(['tasks as open_count' => fn ($q) => $q->whereIn('status_id', $this->workflows->openStatuses())])->orderBy('name')->get()]);
    }

    public function edit(Request $request, ?Workflow $workflow = null): View
    {
        $this->manage($request, $workflow);
        $institutionId = $request->user()->institution_id;
        $workflow?->load('steps.stepType.labels', 'steps.action.labels', 'steps.requiredRole', 'steps.autoAssignUser');

        return view('workflow.edit', ['workflow' => $workflow, 'scopes' => $this->choices('workflow-scope'), 'triggers' => $this->choices('workflow-trigger'),
            'stepTypes' => $this->choices('workflow-step-type'), 'actions' => $this->choices('workflow-action'),
            'roles' => Role::where('institution_id', $institutionId)->with('labels', 'template.labels')->get()->sortBy(fn (Role $r) => $r->name())->values(), 'users' => User::where('institution_id', $institutionId)->orderBy('name')->get(),
            'repositories' => Repository::where('institution_id', $institutionId)->whereNull('deleted_at')->with('names.nameType')->get()->sortBy(fn ($r) => $r->described('authorised-name', ['en'])),
            // ponytail: a collection is chosen from the top-level descriptions; a search when deeper collections need their own workflows.
            'collections' => RecordResource::where('institution_id', $institutionId)->whereNull('deleted_at')->whereNull('parent_id')->with('names.nameType')->get()
                ->sortBy(fn ($r) => mb_strtolower((string) $r->described('title', ['en'])))]);
    }

    public function save(Request $request, ?Workflow $workflow = null): RedirectResponse
    {
        $this->manage($request, $workflow);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'], 'scope' => ['required', 'string', 'max:64'],
            'repository' => ['nullable', 'uuid'], 'record' => ['nullable', 'uuid'], 'trigger' => ['required', 'string', 'max:64'], 'applies_to' => ['required', 'string', 'max:30'],
            'auto_archive_days' => ['nullable', 'string', 'max:5'],
            ...array_fill_keys(['is_active', 'is_default', 'require_all_steps', 'allow_parallel', 'notification_enabled'], ['nullable', 'boolean'])]);
        try {
            $workflow = $this->workflows->saveWorkflow($request->user()->institution, $workflow, $data, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['workflow' => ucfirst($e->getMessage()).'.']);
        }

        return redirect()->route('workflow.admin.edit', $workflow)->with('status', __('Workflow saved.'));
    }

    public function remove(Request $request, Workflow $workflow): RedirectResponse
    {
        $this->manage($request, $workflow);
        try {
            $this->workflows->removeWorkflow($workflow, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['workflow' => ucfirst($e->getMessage()).'.']);
        }

        return redirect()->route('workflow.admin')->with('status', __('Workflow deleted.'));
    }

    public function saveStep(Request $request, Workflow $workflow, ?WorkflowStep $step = null): RedirectResponse
    {
        $this->manage($request, $workflow);
        abort_unless($step === null || $step->workflow_id === $workflow->id, 404);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'], 'step_type' => ['required', 'string', 'max:64'],
            'action' => ['required', 'string', 'max:64'], 'required_role' => ['nullable', 'uuid'], 'allowed_roles' => ['nullable', 'array'], 'allowed_roles.*' => ['uuid'],
            'allowed_users' => ['nullable', 'array'], 'allowed_users.*' => ['uuid'], 'required_clearance_level' => ['nullable', 'string', 'max:1'],
            'auto_assign_user' => ['nullable', 'uuid'], 'escalation_days' => ['nullable', 'string', 'max:4'], 'escalation_user' => ['nullable', 'uuid'],
            'notification_template' => ['nullable', 'string', 'max:100'], 'instructions' => ['nullable', 'string', 'max:20000'], 'checklist' => ['nullable', 'string', 'max:20000'],
            ...array_fill_keys(['pool_enabled', 'is_optional', 'is_active'], ['nullable', 'boolean'])]);
        try {
            $this->workflows->saveStep($workflow, $step, $data, $request->user());
        } catch (InvalidArgumentException $e) {
            return redirect(route('workflow.admin.edit', $workflow).'#steps')->withErrors(['workflow' => ucfirst($e->getMessage()).'.']);
        }

        return redirect(route('workflow.admin.edit', $workflow).'#steps')->with('status', __('Step saved.'));
    }

    public function moveStep(Request $request, Workflow $workflow, WorkflowStep $step): RedirectResponse
    {
        $this->manage($request, $workflow);
        abort_unless($step->workflow_id === $workflow->id, 404);
        $this->workflows->moveStep($step, $request->input('direction') === 'earlier', $request->user());

        return redirect(route('workflow.admin.edit', $workflow).'#steps');
    }

    public function removeStep(Request $request, Workflow $workflow, WorkflowStep $step): RedirectResponse
    {
        $this->manage($request, $workflow);
        abort_unless($step->workflow_id === $workflow->id, 404);
        try {
            $this->workflows->removeStep($step, $request->user());
        } catch (InvalidArgumentException $e) {
            return redirect(route('workflow.admin.edit', $workflow).'#steps')->withErrors(['workflow' => ucfirst($e->getMessage()).'.']);
        }

        return redirect(route('workflow.admin.edit', $workflow).'#steps')->with('status', __('Step deleted.'));
    }

    private function recent(string $institutionId, int $limit)
    {
        return WorkflowHistory::where('institution_id', $institutionId)->with('action.labels', 'performedBy', 'workflow', 'step', 'toStatus.labels', 'task')
            ->orderByDesc('performed_at')->orderByDesc('id')->limit($limit)->get();
    }

    private function bulkChoices(User $user): array
    {
        return ['priorities' => $this->choices('workflow-priority'), 'accounts' => User::where('institution_id', $user->institution_id)->orderBy('name')->get()->reject->isDisabled()->values()];
    }

    private function choices(string $vocabulary)
    {
        return Concept::choices(Vocab::BASE.$vocabulary.'/')->with('labels')->get();
    }

    /** A task of another institution does not exist here. */
    private function own(Request $request, WorkflowTask $task): void
    {
        abort_unless($task->institution_id === $request->user()->institution_id, 404);
    }

    private function manage(Request $request, ?Workflow $workflow = null): void
    {
        abort_unless($workflow === null || $workflow->institution_id === $request->user()->institution_id, 404);
        abort_unless($this->workflows->manages($request->user()), 403);
    }
}
