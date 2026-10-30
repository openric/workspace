<?php

/**
 * WorkflowSlaService - Heratio
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * This file is part of Heratio.
 *
 * Heratio is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * OpenRiC Workspace - ported from PSIS's atom-ahg-plugins/ahgWorkflowPlugin/lib/Services/WorkflowSlaService.php and
 * config/sla_policies.yml at 15fc8f64, with Heratio's ahg-workflow queues and getOverdueTasks at 7663253e9
 * (governance/CODE_PROVENANCE.md, roadmap phase 10b): resolvePolicy with the priority factors, compute,
 * computeForTask, applyPolicy, processSlaBreach with handleBreach and the once-a-day warnings, the overview, the stats
 * by queue and by account, the overdue list and its CSV, and the queues and policies seeded from Heratio's install and
 * PSIS's YAML. Changed: policies are rows of the institution, not YAML; calendar days only (PSIS's business days were
 * switched off); notices through the Outbox; the history is the workflow's.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Description;

use App\Audit\AuditLog;
use App\Mail\Outbox;
use App\Mail\WorkflowTaskMail;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowHistory;
use App\Models\WorkflowQueue;
use App\Models\WorkflowSlaPolicy;
use App\Models\WorkflowTask;
use App\Support\Vocab;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class WorkflowSlaService
{
    /** Heratio's seeded queues: slug => [name, description, service level days, colour]. */
    public const QUEUES = [
        'intake' => ['Intake', 'New submissions awaiting initial review', 2, '#0d6efd'],
        'qc' => ['Quality Control', 'Items undergoing quality checks', 3, '#6610f2'],
        'description' => ['Description', 'Items awaiting descriptive cataloguing', 10, '#6f42c1'],
        'rights' => ['Rights', 'Items requiring rights assessment', 10, '#d63384'],
        'publish' => ['Publish', 'Items ready for publication review', 3, '#28a745'],
        'requests' => ['Requests', 'External access and reproduction requests', 5, '#fd7e14'],
        'movement' => ['Movement', 'Physical movement tracking', 2, '#20c997'],
        'preservation' => ['Preservation', 'Preservation actions and monitoring', 14, '#17a2b8'],
    ];

    /** PSIS's sla_policies.yml: queue slug (null for the default) => [due, warning, escalation days, escalation action]. */
    public const POLICIES = [
        '' => [5, 3, 7, 'notify-lead'], 'intake' => [2, 1, 3, 'notify-lead'], 'qc' => [3, 2, 5, 'notify-lead'], 'description' => [10, 7, 14, 'notify-lead'],
        'rights' => [10, 7, 14, 'notify-admin'], 'publish' => [3, 2, 5, 'notify-lead'], 'requests' => [5, 3, 7, 'notify-admin'],
        'movement' => [2, 1, 3, 'notify-lead'], 'preservation' => [14, 10, 21, 'notify-admin'],
    ];

    /** PSIS's priority factors: an urgent task's days are 40% of a normal one's. */
    public const PRIORITY_FACTORS = ['urgent' => 0.4, 'high' => 0.6, 'normal' => 1.0, 'low' => 1.5];

    /** The standings of a task against its service level, in order. */
    public const STATUSES = ['on-track' => 'On track', 'at-risk' => 'At risk', 'overdue' => 'Overdue', 'breached' => 'Breached', 'completed' => 'Completed in time'];

    public function __construct(private Outbox $outbox) {}

    /** The institution's queues; one that has none is given Heratio's eight, each with PSIS's policy, and the default policy. */
    public function queues(string $institutionId, bool $active = false): Collection
    {
        if (! WorkflowQueue::where('institution_id', $institutionId)->exists()) {
            DB::transaction(function () use ($institutionId) {
                $position = 0;
                foreach (self::QUEUES as $slug => [$name, $description, $days, $color]) {
                    $queue = WorkflowQueue::create(['institution_id' => $institutionId, 'name' => $name, 'slug' => $slug, 'description' => $description,
                        'position' => ++$position, 'sla_days' => $days, 'color' => $color]);
                    $this->seedPolicy($institutionId, $queue);
                }
                $this->seedPolicy($institutionId, null);
            });
        }

        return WorkflowQueue::where('institution_id', $institutionId)->when($active, fn ($q) => $q->where('is_active', true))->orderBy('position')->get();
    }

    /** Add or change a queue. Fields: name, slug, description, sla_days, color, is_active. */
    public function saveQueue(Institution $institution, ?WorkflowQueue $queue, array $fields, User $actor): WorkflowQueue
    {
        $name = trim((string) ($fields['name'] ?? '')) ?: throw new InvalidArgumentException(__('give the queue a name'));
        $slug = trim((string) ($fields['slug'] ?? '')) ?: Str::slug($name);
        preg_match('/^[a-z0-9-]{1,100}$/', $slug) || throw new InvalidArgumentException(__('give the short name in lower case letters, digits and hyphens'));
        WorkflowQueue::where(['institution_id' => $institution->id, 'slug' => $slug])->when($queue, fn ($q) => $q->whereKeyNot($queue->id))->exists()
            && throw new InvalidArgumentException(__('another queue has that short name'));
        $days = trim((string) ($fields['sla_days'] ?? ''));
        $days === '' || ctype_digit($days) && (int) $days > 0 || throw new InvalidArgumentException(__('give the days as a whole number above 0'));
        $color = trim((string) ($fields['color'] ?? ''));
        $color === '' || preg_match('/^#[0-9a-fA-F]{6}$/', $color) || throw new InvalidArgumentException(__('give the colour as #rrggbb'));
        $values = ['name' => $name, 'slug' => $slug, 'description' => trim((string) ($fields['description'] ?? '')) ?: null, 'sla_days' => $days === '' ? null : (int) $days,
            'color' => $color ?: null, 'is_active' => ! array_key_exists('is_active', $fields) || (bool) $fields['is_active']];

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $queue ? tap($queue)->update($values)
            : WorkflowQueue::create([...$values, 'institution_id' => $institution->id, 'position' => (int) WorkflowQueue::where('institution_id', $institution->id)->max('position') + 1])));
    }

    /** The institution's policies, seeded with its queues. */
    public function policies(string $institutionId): Collection
    {
        $this->queues($institutionId);

        return WorkflowSlaPolicy::where('institution_id', $institutionId)->with('queue', 'workflow', 'escalationUser', 'escalationAction.labels')->get()
            ->sortBy(fn ($p) => [$p->queue_id === null && $p->workflow_id === null ? 0 : 1, $p->queue?->position ?? 99, $p->name])->values();
    }

    /**
     * Add or change a policy. Fields: name, queue or workflow (a key; neither for the default), due_days, warning_days,
     * escalation_days, escalation_user (a key), escalation_action (a notation), is_active.
     */
    public function savePolicy(Institution $institution, ?WorkflowSlaPolicy $policy, array $fields, User $actor): WorkflowSlaPolicy
    {
        $name = trim((string) ($fields['name'] ?? '')) ?: throw new InvalidArgumentException(__('give the policy a name'));
        $days = function (string $key, int $min) use ($fields) {
            $v = trim((string) ($fields[$key] ?? ''));
            ctype_digit($v) && (int) $v >= $min && (int) $v <= 3650 || throw new InvalidArgumentException(__('give the days as whole numbers'));

            return (int) $v;
        };
        $values = ['name' => $name, 'due_days' => $days('due_days', 1), 'warning_days' => $days('warning_days', 0), 'escalation_days' => $days('escalation_days', 1),
            'queue_id' => ($fields['queue'] ?? '') === '' ? null : WorkflowQueue::where('institution_id', $institution->id)->findOrFail($fields['queue'])->id,
            'workflow_id' => ($fields['workflow'] ?? '') === '' ? null : Workflow::where('institution_id', $institution->id)->findOrFail($fields['workflow'])->id,
            'escalation_user_id' => ($fields['escalation_user'] ?? '') === '' ? null : User::where('institution_id', $institution->id)->findOrFail($fields['escalation_user'])->id,
            'escalation_action_id' => $this->term('workflow-escalation-action', (string) (($fields['escalation_action'] ?? '') ?: 'notify-lead'))->id,
            'is_active' => ! array_key_exists('is_active', $fields) || (bool) $fields['is_active']];
        $values['warning_days'] <= $values['due_days'] || throw new InvalidArgumentException(__('the warning comes before the task is due'));
        $values['queue_id'] === null || $values['workflow_id'] === null || throw new InvalidArgumentException(__('a policy is for a queue or a workflow, not both'));
        WorkflowSlaPolicy::where(['institution_id' => $institution->id, 'queue_id' => $values['queue_id'], 'workflow_id' => $values['workflow_id']])
            ->when($policy, fn ($q) => $q->whereKeyNot($policy->id))->exists() && throw new InvalidArgumentException(__('there is a policy for that already'));

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $policy ? tap($policy)->update($values)
            : WorkflowSlaPolicy::create([...$values, 'institution_id' => $institution->id])));
    }

    /**
     * PSIS's resolvePolicy: the queue's policy, else the workflow's, else the institution's default, with the days
     * scaled by the priority factor (at least 1).
     *
     * @return array{name: string, due_days: int, warning_days: int, escalation_days: int, escalation_user_id: ?string, escalation_action: string}
     */
    public function resolve(WorkflowTask $task): array
    {
        $this->queues($task->institution_id);
        $active = fn () => WorkflowSlaPolicy::where(['institution_id' => $task->institution_id, 'is_active' => true]);
        $policy = ($task->queue_id ? $active()->where('queue_id', $task->queue_id)->first() : null)
            ?? $active()->where('workflow_id', $task->workflow_id)->first()
            ?? $active()->whereNull('queue_id')->whereNull('workflow_id')->first();
        [$due, $warning, $escalation, $action] = $policy ? [$policy->due_days, $policy->warning_days, $policy->escalation_days, $policy->escalationAction->notation] : self::POLICIES[''];
        $factor = self::PRIORITY_FACTORS[$task->priority->notation] ?? 1.0;
        $scale = fn (int $days) => $factor === 1.0 ? $days : max(1, (int) ceil($days * $factor));

        return ['name' => $policy?->name ?? __('Default'), 'due_days' => $scale($due), 'warning_days' => $warning === 0 ? 0 : $scale($warning),
            'escalation_days' => $scale($escalation), 'escalation_user_id' => $policy?->escalation_user_id, 'escalation_action' => $action];
    }

    /**
     * PSIS's compute: the due date (the task's own, else its policy's from when it was created), when it is at risk,
     * when it escalates, and its standing now.
     *
     * @return array{status: string, label: string, due_at: CarbonImmutable, at_risk_at: CarbonImmutable, escalation_at: CarbonImmutable, days_until_due: int, policy: string}
     */
    public function compute(WorkflowTask $task, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $policy = $this->resolve($task);
        $created = CarbonImmutable::parse($task->created_at);
        $due = $task->due_date ? $task->due_date->setTimezone(config('workspace.timezone'))->endOfDay() : $created->addDays($policy['due_days']);
        $atRisk = $due->subDays($policy['warning_days']);
        $escalation = $created->addDays($policy['escalation_days'])->max($due);
        $closed = ! $task->isOpen();
        $status = match (true) {
            $closed => $task->decision_at !== null && $task->decision_at <= $due ? 'completed' : 'breached',
            $now >= $escalation => 'breached',
            $now >= $due => 'overdue',
            $now >= $atRisk => 'at-risk',
            default => 'on-track',
        };

        return ['status' => $status, 'label' => __(self::STATUSES[$status]), 'due_at' => $due, 'at_risk_at' => $atRisk, 'escalation_at' => $escalation,
            'days_until_due' => (int) $now->startOfDay()->diffInDays($due->startOfDay(), false), 'policy' => $policy['name']];
    }

    /** PSIS's applyPolicy: the due date a new task gets from its policy. */
    public function dueDate(WorkflowTask $task): string
    {
        return CarbonImmutable::now(config('workspace.timezone'))->addDays($this->resolve($task)['due_days'])->toDateString();
    }

    /** Move the task to a queue (or out of any), noting it in the history. */
    public function moveToQueue(WorkflowTask $task, ?WorkflowQueue $queue, User $actor, ?string $correlation = null): void
    {
        $queue === null || $queue->institution_id === $task->institution_id || throw new InvalidArgumentException(__('the queue is one of the institution\'s'));
        app(WorkflowService::class)->mayWork($task, $actor) || throw new InvalidArgumentException(__('this task is not yours to change'));
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($task, $queue, $actor, $correlation) {
            $task->update(['queue_id' => $queue?->id]);
            $this->log($task, 'comment', $actor, $queue ? __('Moved to the queue :name', ['name' => $queue->name]) : __('Taken out of its queue'), $correlation);
        }));
    }

    /**
     * PSIS's processSlaBreach, run each morning: a task at risk or overdue is noted and its account told once a day; a
     * breached task is escalated once, by its policy: its lead told (the policy's or step's escalation account, else
     * whoever submitted it), those who manage workflows told, or the task given to the escalation account.
     *
     * @return array{at_risk: int, overdue: int, escalated: int}
     */
    public function process(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $counts = ['at_risk' => 0, 'overdue' => 0, 'escalated' => 0];
        $workflows = app(WorkflowService::class);
        foreach ($workflows->open()->with('workflow', 'step', 'status', 'priority', 'assignedTo', 'submittedBy')->get() as $task) {
            $sla = $this->compute($task, $now);
            if ($sla['status'] === 'at-risk' && $this->once($task, 'sla-warning', $now)) {
                $counts['at_risk']++;
                $this->tell($task, 'due-soon', $task->assignedTo ?? $task->submittedBy);
            } elseif ($sla['status'] === 'overdue' && $this->once($task, 'sla-breach', $now)) {
                $counts['overdue']++;
                $this->tell($task, 'overdue', $task->assignedTo ?? $task->submittedBy);
            } elseif ($sla['status'] === 'breached' && $task->escalated_at === null) {
                $counts['escalated']++;
                $this->escalate($task, $now);
            }
        }

        return $counts;
    }

    /** Heratio's overview and PSIS's getOverview: how many open tasks stand where, by queue and by account. */
    public function overview(string $institutionId): array
    {
        $tasks = app(WorkflowService::class)->open()->where('institution_id', $institutionId)->with('status', 'priority', 'queue', 'assignedTo', 'step', 'workflow')->get();
        $standing = $tasks->map(fn ($t) => [$t, $this->compute($t)]);

        return ['by_status' => $standing->countBy(fn ($p) => $p[1]['status'])->all(),
            'by_queue' => $standing->groupBy(fn ($p) => $p[0]->queue?->name ?? __('No queue'))->map(fn ($g) => ['open' => $g->count(), 'late' => $g->filter(fn ($p) => in_array($p[1]['status'], ['overdue', 'breached'], true))->count()])->all(),
            'by_user' => $standing->groupBy(fn ($p) => $p[0]->assignedTo?->name ?? __('Nobody (in the pool)'))->map(fn ($g) => ['open' => $g->count(), 'late' => $g->filter(fn ($p) => in_array($p[1]['status'], ['overdue', 'breached'], true))->count()])->sortKeys()->all()];
    }

    /**
     * Heratio's getOverdueTasks: the open tasks overdue or breached, filtered by queue or account, the most overdue first.
     *
     * @return Collection<int, array{0: WorkflowTask, 1: array}>
     */
    public function overdue(string $institutionId, ?string $queueId = null, ?string $userId = null): Collection
    {
        return app(WorkflowService::class)->open()->where('institution_id', $institutionId)->when($queueId, fn ($q) => $q->where('queue_id', $queueId))
            ->when($userId, fn ($q) => $q->where('assigned_to_id', $userId))->with('status.labels', 'priority.labels', 'queue', 'assignedTo', 'step', 'workflow')->get()
            ->map(fn ($t) => [$t, $this->compute($t)])->filter(fn ($p) => in_array($p[1]['status'], ['overdue', 'breached'], true))
            ->sortBy(fn ($p) => $p[1]['due_at'])->values();
    }

    /** PSIS's exportOverdueCsv. @return list<list<?string>> */
    public function csv(Collection $overdue): array
    {
        $titles = app(WorkflowService::class);
        $rows = [['Task', 'For', 'Workflow', 'Queue', 'Assigned to', 'Priority', 'Due', 'Days overdue', 'Standing', 'Policy']];
        foreach ($overdue as [$task, $sla]) {
            $subject = $task->subject();
            $rows[] = [$task->step->name, $subject ? $titles->title($subject) : null, $task->workflow->name, $task->queue?->name, $task->assignedTo?->name,
                $task->priority->label(['en']), $sla['due_at']->toDateString(), (string) max(0, -$sla['days_until_due']), $sla['label'], $sla['policy']];
        }

        return $rows;
    }

    private function escalate(WorkflowTask $task, CarbonImmutable $now): void
    {
        $policy = $this->resolve($task);
        $lead = User::find($policy['escalation_user_id'] ?? $task->step->escalation_user_id);
        DB::transaction(function () use ($task, $policy, $lead, $now) {
            $values = ['escalated_at' => $now];
            if ($policy['escalation_action'] === 'auto-reassign' && $lead && ! $lead->isDisabled()) {
                $values += ['assigned_to_id' => $lead->id, 'status_id' => $this->term('workflow-task-status', 'claimed')->id, 'claimed_at' => now()];
            }
            $task->update($values);
            $this->log($task, 'escalated', null, __('Service level breached (:policy): :action', ['policy' => $policy['name'],
                'action' => $this->term('workflow-escalation-action', $policy['escalation_action'])->label(['en'])]), null, $now);
        });
        $to = match ($policy['escalation_action']) {
            'notify-admin' => User::where('institution_id', $task->institution_id)->get()->filter(fn (User $u) => ! $u->isDisabled() && app(WorkflowService::class)->manages($u)),
            default => collect([$lead ?? $task->submittedBy])->filter(),
        };
        foreach ($to as $user) {
            $this->tell($task, 'escalated', $user);
        }
    }

    /** Note the event in the task's history, unless it was noted today; true when it is new. */
    private function once(WorkflowTask $task, string $action, CarbonImmutable $now): bool
    {
        $actionId = $this->term('workflow-history-action', $action)->id;
        if (WorkflowHistory::where(['task_id' => $task->id, 'action_id' => $actionId])->where('performed_at', '>=', $now->setTimezone(config('workspace.timezone'))->startOfDay())->exists()) {
            return false;
        }
        $this->log($task, $action, null, null, null, $now);

        return true;
    }

    private function tell(WorkflowTask $task, string $event, ?User $to): void
    {
        if ($to !== null && $task->workflow->notification_enabled && $to->institution_id === $task->institution_id) {
            $this->outbox->send($to, new WorkflowTaskMail($to, $task->fresh(['workflow', 'step']), $event));
        }
    }

    private function log(WorkflowTask $task, string $action, ?User $actor, ?string $comment = null, ?string $correlation = null, ?CarbonImmutable $at = null): void
    {
        WorkflowHistory::create(['institution_id' => $task->institution_id, 'task_id' => $task->id, 'workflow_id' => $task->workflow_id, 'step_id' => $task->step_id,
            'subject_table' => $task->subject_table, 'subject_id' => $task->subject_id, 'action_id' => $this->term('workflow-history-action', $action)->id,
            'performed_by_id' => $actor?->id, 'performed_at' => $at ?? now(), 'comment' => $comment, 'correlation_id' => $correlation]);
    }

    private function seedPolicy(string $institutionId, ?WorkflowQueue $queue): void
    {
        [$due, $warning, $escalation, $action] = self::POLICIES[$queue?->slug ?? ''];
        WorkflowSlaPolicy::create(['institution_id' => $institutionId, 'name' => $queue ? __(':queue queue', ['queue' => $queue->name]) : __('Default'), 'queue_id' => $queue?->id,
            'due_days' => $due, 'warning_days' => $warning, 'escalation_days' => $escalation, 'escalation_action_id' => $this->term('workflow-escalation-action', $action)->id]);
    }

    /** @var array<string, Concept> */
    private array $terms = [];

    private function term(string $vocabulary, string $notation): Concept
    {
        return $this->terms["$vocabulary/$notation"] ??= Concept::choices(Vocab::BASE.$vocabulary.'/')->where('notation', $notation)->first()
            ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
