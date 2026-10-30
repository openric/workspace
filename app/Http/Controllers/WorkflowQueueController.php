<?php

// OpenRiC Workspace - work queues, service levels, overdue tasks, publish gates and readiness, and the workflow
// diagram (Heratio's ahg-workflow queues, overdue, gate admin, publish readiness and simulate, diagram and task
// diagram; PSIS's ahgWorkflowPlugin SLA and publish gate services; roadmap phase 10b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Queues and overdue tasks are every signed-in account's to see; changing queues, policies, gate rules and the
// publishing requirement needs Manage workflows. Readiness needs read on the description.

namespace App\Http\Controllers;

use App\Description\Elements;
use App\Description\PublishGateService;
use App\Description\WorkflowService;
use App\Description\WorkflowSlaService;
use App\Models\Concept;
use App\Models\PublishGateRule;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowQueue;
use App\Models\WorkflowSlaPolicy;
use App\Models\WorkflowTask;
use App\Rights\CommunityProtocols;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkflowQueueController extends Controller
{
    public function __construct(private WorkflowSlaService $sla, private WorkflowService $workflows, private PublishGateService $gates) {}

    /** Heratio's queues: each with its open and overdue tasks and service level; PSIS's overview. */
    public function queues(Request $request): View
    {
        $institutionId = $request->user()->institution_id;
        $queues = $this->sla->queues($institutionId);
        $open = $this->workflows->open()->where('institution_id', $institutionId)->with('status', 'priority')->get();

        return view('workflow.queues', ['queues' => $queues, 'overview' => $this->sla->overview($institutionId), 'manages' => $this->workflows->manages($request->user()),
            'counts' => $queues->mapWithKeys(fn ($q) => [$q->id => ['open' => $open->where('queue_id', $q->id)->count(),
                'late' => $open->where('queue_id', $q->id)->filter(fn ($t) => in_array($this->sla->compute($t)['status'], ['overdue', 'breached'], true))->count()]]),
            'policies' => $this->workflows->manages($request->user()) ? $this->sla->policies($institutionId) : collect(),
            'actions' => Concept::choices(Vocab::BASE.'workflow-escalation-action/')->with('labels')->get(),
            'users' => User::where('institution_id', $institutionId)->orderBy('name')->get(), 'workflowList' => Workflow::where('institution_id', $institutionId)->orderBy('name')->get()]);
    }

    public function saveQueue(Request $request, ?WorkflowQueue $queue = null): RedirectResponse
    {
        $this->manage($request, $queue?->institution_id);
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'slug' => ['nullable', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:2000'],
            'sla_days' => ['nullable', 'string', 'max:5'], 'color' => ['nullable', 'string', 'max:7'], 'is_active' => ['nullable', 'boolean']]);

        return $this->attempt(fn () => $this->sla->saveQueue($request->user()->institution, $queue, $data, $request->user()), route('workflow.queues').'#queues', __('Queue saved.'));
    }

    public function savePolicy(Request $request, ?WorkflowSlaPolicy $policy = null): RedirectResponse
    {
        $this->manage($request, $policy?->institution_id);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'queue' => ['nullable', 'uuid'], 'workflow' => ['nullable', 'uuid'], 'due_days' => ['required', 'string', 'max:4'],
            'warning_days' => ['required', 'string', 'max:4'], 'escalation_days' => ['required', 'string', 'max:4'], 'escalation_user' => ['nullable', 'uuid'],
            'escalation_action' => ['required', 'string', 'max:64'], 'is_active' => ['nullable', 'boolean']]);

        return $this->attempt(fn () => $this->sla->savePolicy($request->user()->institution, $policy, $data, $request->user()), route('workflow.queues').'#policies', __('Policy saved.'));
    }

    /** Move a task to a queue. */
    public function move(Request $request, WorkflowTask $task): RedirectResponse
    {
        abort_unless($task->institution_id === $request->user()->institution_id, 404);
        $queue = ($key = (string) $request->input('queue', '')) === '' ? null : WorkflowQueue::where('institution_id', $task->institution_id)->findOrFail($key);

        return $this->attempt(fn () => $this->sla->moveToQueue($task, $queue, $request->user()), route('workflow.tasks.show', $task), __('Saved.'));
    }

    /** Heratio's overdue page, filtered by queue or account, and PSIS's CSV of it. */
    public function overdue(Request $request): View|StreamedResponse
    {
        $institutionId = $request->user()->institution_id;
        $queue = (string) $request->query('queue', '');
        $user = (string) $request->query('user', '');
        $overdue = $this->sla->overdue($institutionId, $queue ?: null, $user ?: null);
        if ($request->query('format') === 'csv') {
            $rows = $this->sla->csv($overdue);

            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                foreach ($rows as $row) {
                    fputcsv($out, $row);
                }
                fclose($out);
            }, 'overdue-tasks.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return view('workflow.overdue', ['overdue' => $overdue, 'queue' => $queue, 'user' => $user, 'queues' => $this->sla->queues($institutionId),
            'users' => User::where('institution_id', $institutionId)->orderBy('name')->get()]);
    }

    /** Heratio's gate admin, and the requirement of an approved workflow before publishing. */
    public function gates(Request $request): View
    {
        $this->manage($request);
        $institution = $request->user()->institution;

        return view('workflow.gates', ['rules' => $this->gates->rules($institution->id), 'institution' => $institution->fresh(),
            'types' => Concept::choices(Vocab::BASE.'publish-gate-rule-type/')->with('labels')->get(), 'severities' => Concept::choices(Vocab::BASE.'publish-gate-severity/')->with('labels')->get(),
            'levels' => Concept::bundledThenLocal(Vocab::BASE.'level-of-description/', 'level-of-description', $institution->id),
            'repositories' => Repository::where('institution_id', $institution->id)->whereNull('deleted_at')->with('names.nameType')->get(),
            'elements' => Elements::of(Elements::ISAD_G)]);
    }

    public function saveGate(Request $request, ?PublishGateRule $rule = null): RedirectResponse
    {
        $this->manage($request, $rule?->institution_id);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'rule_type' => ['required', 'string', 'max:64'], 'severity' => ['required', 'string', 'max:64'],
            'level' => ['nullable', 'uuid'], 'repository' => ['nullable', 'uuid'], 'field_name' => ['nullable', 'string', 'max:100'], 'min_length' => ['nullable', 'string', 'max:6'],
            'error_message' => ['required', 'string', 'max:500'], 'position' => ['nullable', 'integer'], 'is_active' => ['nullable', 'boolean']]);

        return $this->attempt(fn () => $this->gates->saveRule($request->user()->institution, $rule, $data, $request->user()), route('workflow.gates'), __('Rule saved.'));
    }

    public function removeGate(Request $request, PublishGateRule $rule): RedirectResponse
    {
        $this->manage($request, $rule->institution_id);
        $rule->delete();

        return redirect()->route('workflow.gates')->with('status', __('Removed.'));
    }

    /** Heratio's workflow_required_for_publish. */
    public function publishing(Request $request): RedirectResponse
    {
        $this->manage($request);
        $request->user()->institution->update(['workflow_required_for_publish' => $request->boolean('workflow_required_for_publish')]);

        return redirect()->route('workflow.gates')->with('status', __('Saved.'));
    }

    /** Heratio's publish readiness and PSIS's publish simulate for a description. */
    public function readiness(Request $request, RecordResource $record): View
    {
        abort_unless($record->institution_id === $request->user()->institution_id && $record->deleted_at === null, 404);
        abort_unless($request->user()->may('read', 'description', $record), 403);
        abort_unless(app(CommunityProtocols::class)->allows($request->user(), $record, 'description'), 404);
        $language = (string) $request->query('language', app()->getLocale());

        return view('workflow.readiness', ['record' => $record, 'results' => $this->gates->evaluate($record, $request->user()), 'history' => $this->gates->history($record, 20),
            'simulation' => $this->gates->simulate($record->load('dates', 'repository.names.nameType'), $language), 'language' => $language]);
    }

    /** Heratio's diagram of a workflow, and of a task's place in it. */
    public function diagram(Request $request, Workflow $workflow): View
    {
        abort_unless($workflow->institution_id === $request->user()->institution_id, 404);

        return view('workflow.diagram', ['workflow' => $workflow->load('steps.stepType.labels', 'steps.action.labels', 'steps.requiredRole'), 'task' => null]);
    }

    public function taskDiagram(Request $request, WorkflowTask $task): View
    {
        abort_unless($task->institution_id === $request->user()->institution_id, 404);
        $done = WorkflowTask::where(['workflow_id' => $task->workflow_id, 'subject_table' => $task->subject_table, 'subject_id' => $task->subject_id])
            ->whereHas('decision', fn ($q) => $q->where('notation', 'approved'))->pluck('step_id')->all();

        return view('workflow.diagram', ['workflow' => $task->workflow->load('steps.stepType.labels', 'steps.action.labels', 'steps.requiredRole'), 'task' => $task, 'done' => $done]);
    }

    private function attempt(callable $change, string $to, string $status): RedirectResponse
    {
        try {
            $change();
        } catch (InvalidArgumentException $e) {
            return redirect($to)->withInput()->withErrors(['workflow' => ucfirst($e->getMessage()).'.']);
        }

        return redirect($to)->with('status', $status);
    }

    private function manage(Request $request, ?string $institutionId = null): void
    {
        abort_unless($institutionId === null || $institutionId === $request->user()->institution_id, 404);
        abort_unless($this->workflows->manages($request->user()), 403);
    }
}
