<?php

/**
 * WorkflowService - Heratio
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
 * OpenRiC Workspace - ported from Heratio, packages/ahg-workflow/src/Services/WorkflowService.php and
 * WorkflowBulkService.php at commit 7663253e9, and PSIS's atom-ahg-plugins/ahgWorkflowPlugin/lib/Services/
 * WorkflowService.php at 15fc8f64 where Heratio's screens are empty (governance/CODE_PROVENANCE.md, roadmap phase
 * 10a): the workflows and steps, startWorkflow with PSIS's getApplicableWorkflow, claim, assign, release, approve
 * (with the next step), reject, PSIS's returnTask and resubmitTask, notes, priority and due date, the stats, pool, my
 * tasks and team work, the history, and the bulk operations. Kept: the steps in order, the pool, only the assignee
 * deciding, a task under a row lock while it changes. Changed: the Workspace's tables and UUIDs; every list a
 * vocabulary; a team is a role; a task needs update on its record besides the step's roles and accounts; mail through
 * the Outbox; the audit log is the Workspace's. Queues and service levels (WorkflowSlaService) and the publish gates (PublishGateService) came with phase 10b.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Description;

use App\Audit\AuditLog;
use App\Mail\Outbox;
use App\Mail\WorkflowTaskMail;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\Role;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowHistory;
use App\Models\WorkflowQueue;
use App\Models\WorkflowStep;
use App\Models\WorkflowTask;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class WorkflowService
{
    /** The kinds a workflow moves, and the table of each. */
    public const KINDS = ['record' => 'record_resources', 'agent' => 'agents', 'repository' => 'repositories', 'accession' => 'accessions'];

    /** The bulk operations (Heratio's WorkflowBulkService). */
    public const BULK = ['assign', 'priority', 'queue', 'note', 'approve', 'reject', 'cancel'];

    /** @var array<string, Concept> the concepts looked up, by vocabulary and notation */
    private array $terms = [];

    public function __construct(private Outbox $outbox) {}

    // Workflows and steps

    /**
     * Add or change a workflow. Fields: name, description, scope (a notation) with repository or record (a key) for
     * its scope, trigger (a notation), applies_to (a kind), auto_archive_days, and the flags is_active, is_default,
     * require_all_steps, allow_parallel, notification_enabled.
     */
    public function saveWorkflow(Institution $institution, ?Workflow $workflow, array $fields, User $actor): Workflow
    {
        $name = trim((string) ($fields['name'] ?? '')) ?: throw new InvalidArgumentException(__('give the workflow a name'));
        $scope = $this->term('workflow-scope', (string) (($fields['scope'] ?? '') ?: 'global'));
        $kind = (string) (($fields['applies_to'] ?? '') ?: 'record');
        isset(self::KINDS[$kind]) || throw new InvalidArgumentException(__('a workflow moves descriptions, authority records, repositories or accessions'));
        $repository = $scope->notation === 'repository' ? (Repository::where('institution_id', $institution->id)->whereNull('deleted_at')->find($fields['repository'] ?? null)
            ?? throw new InvalidArgumentException(__('choose the repository it applies to'))) : null;
        $record = $scope->notation === 'collection' ? (RecordResource::where('institution_id', $institution->id)->whereNull('deleted_at')->find($fields['record'] ?? null)
            ?? throw new InvalidArgumentException(__('choose the description it applies to, with everything inside it'))) : null;
        $days = trim((string) ($fields['auto_archive_days'] ?? ''));
        $days === '' || ctype_digit($days) && (int) $days > 0 || throw new InvalidArgumentException(__('give the days as a whole number above 0'));
        $flag = fn (string $key, bool $default) => array_key_exists($key, $fields) ? (bool) $fields[$key] : $default;
        $values = ['name' => $name, 'description' => trim((string) ($fields['description'] ?? '')) ?: null, 'scope_id' => $scope->id,
            'scope_repository_id' => $repository?->id, 'scope_record_id' => $record?->id, 'trigger_id' => $this->term('workflow-trigger', (string) (($fields['trigger'] ?? '') ?: 'manual'))->id,
            'applies_to' => $kind, 'auto_archive_days' => $days === '' ? null : (int) $days, 'is_active' => $flag('is_active', true),
            'is_default' => $flag('is_default', false) && $scope->notation === 'global', 'require_all_steps' => $flag('require_all_steps', true),
            'allow_parallel' => $flag('allow_parallel', false), 'notification_enabled' => $flag('notification_enabled', true)];

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($institution, $workflow, $values, $actor) {
            if ($values['is_default']) {
                Workflow::where(['institution_id' => $institution->id, 'applies_to' => $values['applies_to'], 'is_default' => true])
                    ->when($workflow, fn ($q) => $q->whereKeyNot($workflow->id))->get()->each->update(['is_default' => false]);
            }

            return $workflow ? tap($workflow)->update($values) : Workflow::create([...$values, 'institution_id' => $institution->id, 'created_by_id' => $actor->id]);
        }));
    }

    /** Heratio's deleteWorkflow: a workflow with tasks still open is kept. */
    public function removeWorkflow(Workflow $workflow, User $actor): void
    {
        $this->open()->where('workflow_id', $workflow->id)->exists() && throw new InvalidArgumentException(__('the workflow has tasks still open'));
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $workflow->delete()));
    }

    /**
     * Add or change a step at the end of the workflow. Fields: name, description, step_type, action (notations),
     * required_role (a key), allowed_roles and allowed_users (lists of keys), required_clearance_level, auto_assign_user,
     * escalation_days, escalation_user, notification_template, instructions, checklist (one item per line), and the
     * flags pool_enabled, is_optional, is_active.
     */
    public function saveStep(Workflow $workflow, ?WorkflowStep $step, array $fields, User $actor): WorkflowStep
    {
        $name = trim((string) ($fields['name'] ?? '')) ?: throw new InvalidArgumentException(__('give the step a name'));
        $role = fn (?string $key) => ($key = trim((string) $key)) === '' ? null : (Role::where('institution_id', $workflow->institution_id)->find($key)
            ?? throw new InvalidArgumentException(__('the role is one of the institution\'s')));
        $user = fn (?string $key) => ($key = trim((string) $key)) === '' ? null : (User::where('institution_id', $workflow->institution_id)->find($key)
            ?? throw new InvalidArgumentException(__('the account is one of the institution\'s')));
        $number = function (string $key, int $min, int $max) use ($fields) {
            $value = trim((string) ($fields[$key] ?? ''));
            $value === '' || ctype_digit($value) && (int) $value >= $min && (int) $value <= $max || throw new InvalidArgumentException(__('give a whole number from :min to :max', ['min' => $min, 'max' => $max]));

            return $value === '' ? null : (int) $value;
        };
        $ids = fn (string $key, callable $find) => ($list = collect($fields[$key] ?? [])->filter()->map(fn ($k) => $find($k)->id)->unique()->values()->all()) === [] ? null : $list;
        $checklist = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($fields['checklist'] ?? ''))), fn ($l) => $l !== ''));
        $flag = fn (string $key, bool $default) => array_key_exists($key, $fields) ? (bool) $fields[$key] : $default;
        $values = ['name' => $name, 'description' => trim((string) ($fields['description'] ?? '')) ?: null,
            'step_type_id' => $this->term('workflow-step-type', (string) (($fields['step_type'] ?? '') ?: 'review'))->id,
            'action_id' => $this->term('workflow-action', (string) (($fields['action'] ?? '') ?: 'approve-reject'))->id,
            'required_role_id' => $role($fields['required_role'] ?? null)?->id, 'allowed_role_ids' => $ids('allowed_roles', $role), 'allowed_user_ids' => $ids('allowed_users', $user),
            'required_clearance_level' => $number('required_clearance_level', 0, 9), 'auto_assign_user_id' => $user($fields['auto_assign_user'] ?? null)?->id,
            'escalation_days' => $number('escalation_days', 1, 3650), 'escalation_user_id' => $user($fields['escalation_user'] ?? null)?->id,
            'notification_template' => trim((string) ($fields['notification_template'] ?? '')) ?: null, 'instructions' => trim((string) ($fields['instructions'] ?? '')) ?: null,
            'checklist' => $checklist === [] ? null : $checklist, 'pool_enabled' => $flag('pool_enabled', true), 'is_optional' => $flag('is_optional', false), 'is_active' => $flag('is_active', true)];

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $step ? tap($step)->update($values)
            : WorkflowStep::create([...$values, 'workflow_id' => $workflow->id, 'position' => (int) $workflow->steps()->max('position') + 1])));
    }

    /** A step with tasks still open is kept; the rest are numbered 1, 2, 3 again. */
    public function removeStep(WorkflowStep $step, User $actor): void
    {
        $this->open()->where('step_id', $step->id)->exists() && throw new InvalidArgumentException(__('the step has tasks still open'));
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($step) {
            $workflow = $step->workflow;
            $step->delete();
            foreach ($workflow->steps()->get()->values() as $i => $s) {
                $s->position === $i + 1 || $s->update(['position' => $i + 1]);
            }
        }));
    }

    /** PSIS's reorderSteps, one place at a time: earlier ($earlier) or later. */
    public function moveStep(WorkflowStep $step, bool $earlier, User $actor): void
    {
        $steps = $step->workflow->steps()->get()->values();
        $other = $steps->get($steps->search(fn ($s) => $s->id === $step->id) + ($earlier ? -1 : 1));
        if ($other) {
            DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($step, $other) {
                [$a, $b] = [$step->position, $other->position];
                $step->update(['position' => $b]);
                $other->update(['position' => $a]);
            }));
        }
    }

    /**
     * PSIS's getApplicableWorkflow: for a description, the nearest collection workflow from it upwards, then its
     * repository's, then the institution's default; for anything else, the default for its kind.
     */
    public function applicable(Model $subject): ?Workflow
    {
        $kind = EntityKinds::of($subject);
        $active = fn () => Workflow::where(['institution_id' => $subject->getAttribute('institution_id'), 'applies_to' => $kind, 'is_active' => true])
            ->whereHas('steps', fn ($q) => $q->where('is_active', true));
        if ($subject instanceof RecordResource) {
            foreach ([$subject->id, ...array_reverse(array_map(fn ($a) => $a->id, $subject->ancestors()))] as $id) {
                if ($workflow = $active()->where('scope_record_id', $id)->first()) {
                    return $workflow;
                }
            }
            if ($subject->repository_id && $workflow = $active()->where('scope_repository_id', $subject->repository_id)->first()) {
                return $workflow;
            }
        }

        return $active()->where('is_default', true)->first();
    }

    // Tasks

    /**
     * Start a workflow (the applicable one when none is given) for the subject: a task for its first active step, given
     * to the step's account when it has one, due after its escalation days. A subject with a task still open is refused.
     */
    public function start(Model $subject, User $actor, ?Workflow $workflow = null, ?string $priority = null, ?string $dueDate = null, ?string $correlation = null, ?WorkflowQueue $queue = null): WorkflowTask
    {
        $workflow ??= $this->applicable($subject) ?? throw new InvalidArgumentException(__('no workflow applies to it'));
        $workflow->institution_id === $subject->getAttribute('institution_id') && $workflow->is_active || throw new InvalidArgumentException(__('the workflow is not one of the institution\'s active workflows'));
        $workflow->applies_to === EntityKinds::of($subject) || throw new InvalidArgumentException(__('the workflow moves another kind of entity'));
        $this->open()->where(['subject_table' => $subject->getTable(), 'subject_id' => $subject->getKey()])->exists()
            && throw new InvalidArgumentException(__('it is in a workflow already'));
        $first = $workflow->steps()->where('is_active', true)->first() ?? throw new InvalidArgumentException(__('the workflow has no steps'));
        $due = $this->day($dueDate) ?? ($first->escalation_days ? now()->addDays($first->escalation_days)->toDateString() : null);

        $queue === null || $queue->institution_id === $workflow->institution_id || throw new InvalidArgumentException(__('the queue is one of the institution\'s'));

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($subject, $actor, $workflow, $first, $priority, $due, $correlation, $queue) {
            $task = WorkflowTask::create(['institution_id' => $workflow->institution_id, 'workflow_id' => $workflow->id, 'step_id' => $first->id, 'queue_id' => $queue?->id,
                'subject_table' => $subject->getTable(), 'subject_id' => $subject->getKey(), 'status_id' => $this->status($first->auto_assign_user_id ? 'claimed' : 'pending'),
                'priority_id' => $this->term('workflow-priority', $priority ?: 'normal')->id, 'submitted_by_id' => $actor->id, 'assigned_to_id' => $first->auto_assign_user_id,
                'claimed_at' => $first->auto_assign_user_id ? now() : null, 'due_date' => $due, 'decision_id' => $this->decision('pending')]);
            // With no due date given or set by the step, its service level policy sets one (PSIS's applyPolicy).
            if ($task->due_date === null) {
                $task->update(['due_date' => app(WorkflowSlaService::class)->dueDate($task)]);
                $this->log($task, 'sla-policy-applied', null, null, $actor, null, $correlation);
            }
            $this->log($task, 'started', null, $task->status->notation, $actor, __('Workflow started: :name', ['name' => $workflow->name]), $correlation);
            $this->notify($task, 'assigned', $task->assignedTo);

            return $task;
        }));
    }

    /**
     * Start the workflow for a description and, with $inside, everything inside it the account may change: how a team
     * divides a fonds. Those in a workflow already, or the account may not change, are skipped and counted.
     *
     * @return array{started: int, skipped: int}
     */
    public function startTree(RecordResource $record, User $actor, ?Workflow $workflow, bool $inside, ?string $priority = null, ?string $dueDate = null, ?WorkflowQueue $queue = null): array
    {
        $ids = $inside ? [$record->id, ...$record->descendantIds()] : [$record->id];
        $correlation = (string) Str::uuid7();
        $started = $skipped = 0;
        foreach (RecordResource::whereIn('id', $ids)->whereNull('deleted_at')->get() as $unit) {
            try {
                $actor->may('update', 'description', $unit) || throw new InvalidArgumentException('not yours to change');
                $this->start($unit, $actor, $workflow, $priority, $dueDate, $correlation, $queue);
                $started++;
            } catch (InvalidArgumentException) {
                $skipped++;
            }
        }
        if (! $inside && $started === 0) {
            throw new InvalidArgumentException(__('it is in a workflow already, no workflow applies to it, or it is not yours to change'));
        }

        return ['started' => $started, 'skipped' => $skipped];
    }

    /** Heratio's claimTask: an unassigned task in the pool, for an account the step allows that may change its subject. */
    public function claim(WorkflowTask $task, User $user, ?string $correlation = null): void
    {
        $this->locked($task, function (WorkflowTask $task) use ($user, $correlation) {
            $task->assigned_to_id === null && $task->status->notation === 'pending' || throw new InvalidArgumentException(__('the task is not in the pool'));
            $task->step->pool_enabled || throw new InvalidArgumentException(__('this step is assigned, not claimed'));
            $this->eligible($task, $user) || throw new InvalidArgumentException(__('this step is not yours to take'));
            $task->update(['assigned_to_id' => $user->id, 'status_id' => $this->status('claimed'), 'claimed_at' => now()]);
            $this->log($task, 'claimed', 'pending', 'claimed', $user, null, $correlation);
        }, $user);
    }

    /** Heratio's assignToUser: give the task to an account the step allows, with a note of why. */
    public function assign(WorkflowTask $task, User $assignee, User $actor, ?string $comment = null, ?string $correlation = null): void
    {
        $this->locked($task, function (WorkflowTask $task) use ($assignee, $actor, $comment, $correlation) {
            $task->isOpen() && $task->status->notation !== 'returned' || throw new InvalidArgumentException(__('the task is not open'));
            $this->manages($actor) || $this->eligible($task, $actor) || throw new InvalidArgumentException(__('only someone who may take the step, or manages workflows, may assign it'));
            $assignee->institution_id === $task->institution_id && ! $assignee->isDisabled() || throw new InvalidArgumentException(__('assign it to an account of the institution'));
            $this->eligible($task, $assignee) || throw new InvalidArgumentException(__(':name may not take this step', ['name' => $assignee->name]));
            $from = $task->status->notation;
            $task->update(['assigned_to_id' => $assignee->id, 'status_id' => $this->status('claimed'), 'claimed_at' => now()]);
            $this->log($task, 'reassigned', $from, 'claimed', $actor, ($c = trim((string) $comment)) !== '' ? $c : __('Assigned to :name', ['name' => $assignee->name]), $correlation);
            $assignee->id === $actor->id || $this->notify($task, 'assigned', $assignee, $comment);
        }, $actor);
    }

    /** Heratio's releaseTask: back to the pool, by whoever has it or someone who manages workflows. */
    public function release(WorkflowTask $task, User $user, ?string $comment = null): void
    {
        $this->locked($task, function (WorkflowTask $task) use ($user, $comment) {
            in_array($task->status->notation, ['claimed', 'in-progress'], true) || throw new InvalidArgumentException(__('the task is not taken'));
            $task->assigned_to_id === $user->id || $this->manages($user) || throw new InvalidArgumentException(__('only whoever has the task may release it'));
            $from = $task->status->notation;
            $task->update(['assigned_to_id' => null, 'status_id' => $this->status('pending'), 'claimed_at' => null]);
            $this->log($task, 'released', $from, 'pending', $user, $comment);
        }, $user);
    }

    /** Begin work on a claimed task. */
    public function begin(WorkflowTask $task, User $user): void
    {
        $this->locked($task, function (WorkflowTask $task) use ($user) {
            $task->assigned_to_id === $user->id && $task->status->notation === 'claimed' || throw new InvalidArgumentException(__('only whoever has the task may begin it'));
            $task->update(['status_id' => $this->status('in-progress')]);
            $this->log($task, 'comment', 'claimed', 'in-progress', $user, __('Work begun'));
        }, $user);
    }

    /**
     * Decide the task (Heratio's approveTask and rejectTask, PSIS's returnTask): only whoever has it. Approved, the next
     * active step gets a task, or the workflow is completed; rejected or returned needs a comment. $checklist is the
     * items ticked.
     *
     * @param  list<string>  $checklist
     */
    public function decide(WorkflowTask $task, string $decision, User $user, ?string $comment = null, array $checklist = [], ?string $correlation = null): ?WorkflowTask
    {
        in_array($decision, ['approved', 'rejected', 'returned'], true) || throw new InvalidArgumentException(__('approve, reject or return it'));
        $comment = ($c = trim((string) $comment)) === '' ? null : $c;
        $decision === 'approved' || $comment !== null || throw new InvalidArgumentException(__('say why'));
        $next = null;
        $this->locked($task, function (WorkflowTask $task) use ($decision, $user, $comment, $checklist, $correlation, &$next) {
            $task->assigned_to_id === $user->id && in_array($task->status->notation, ['claimed', 'in-progress'], true)
                || throw new InvalidArgumentException(__('only whoever has the task may decide it'));
            $items = $task->step->checklist ?? [];
            $ticked = array_values(array_intersect($items, $checklist));
            $decision !== 'approved' || ! $task->workflow->require_all_steps || count($ticked) === count($items)
                || throw new InvalidArgumentException(__('tick every item of the checklist first'));
            $from = $task->status->notation;
            $task->update(['status_id' => $this->status($decision), 'decision_id' => $this->decision($decision), 'decision_comment' => $comment, 'decision_at' => now(),
                'decision_by_id' => $user->id, 'checklist_completed' => $ticked === [] ? null : $ticked, 'retry_count' => $task->retry_count + ($decision === 'returned' ? 1 : 0)]);
            $this->log($task, $decision, $from, $decision, $user, $comment, $correlation);
            if ($decision !== 'approved') {
                $this->notify($task, $decision, $task->submittedBy, $comment);

                return;
            }
            $step = $task->workflow->steps()->where('is_active', true)->where('position', '>', $task->step->position)->first();
            if ($step === null) {
                $this->log($task, 'completed', 'approved', 'approved', $user, __('Workflow completed'), $correlation);
                $this->notify($task, 'completed', $task->submittedBy);

                return;
            }
            $next = WorkflowTask::create(['institution_id' => $task->institution_id, 'workflow_id' => $task->workflow_id, 'step_id' => $step->id,
                'subject_table' => $task->subject_table, 'subject_id' => $task->subject_id, 'status_id' => $this->status($step->auto_assign_user_id ? 'claimed' : 'pending'),
                'priority_id' => $task->priority_id, 'submitted_by_id' => $task->submitted_by_id, 'assigned_to_id' => $step->auto_assign_user_id,
                'claimed_at' => $step->auto_assign_user_id ? now() : null, 'due_date' => $step->escalation_days ? now()->addDays($step->escalation_days)->toDateString() : $task->due_date,
                'decision_id' => $this->decision('pending'), 'previous_task_id' => $task->id, 'queue_id' => $task->queue_id]);
            $this->log($next, 'created', null, $next->status->notation, $user, __('Created when :step was approved', ['step' => $task->step->name]), $correlation);
            $this->notify($next, 'assigned', $next->assignedTo);
        }, $user);

        return $next;
    }

    /** PSIS's resubmitTask: a returned task, by whoever submitted it, goes back to the pool. */
    public function resubmit(WorkflowTask $task, User $user, ?string $comment = null): void
    {
        $this->locked($task, function (WorkflowTask $task) use ($user, $comment) {
            $task->status->notation === 'returned' && $task->submitted_by_id === $user->id || throw new InvalidArgumentException(__('only whoever submitted a returned task may resubmit it'));
            $task->update(['status_id' => $this->status('pending'), 'assigned_to_id' => null, 'claimed_at' => null, 'decision_id' => $this->decision('pending'),
                'decision_comment' => null, 'decision_at' => null, 'decision_by_id' => null]);
            $this->log($task, 'resubmitted', 'returned', 'pending', $user, ($c = trim((string) $comment)) !== '' ? $c : __('Resubmitted after revision'));
        }, $user);
    }

    /** Stop an open task, by someone who manages workflows. */
    public function cancel(WorkflowTask $task, User $user, ?string $comment = null, ?string $correlation = null): void
    {
        $this->locked($task, function (WorkflowTask $task) use ($user, $comment, $correlation) {
            $task->isOpen() || throw new InvalidArgumentException(__('the task is not open'));
            $this->manages($user) || throw new InvalidArgumentException(__('only someone who manages workflows may cancel a task'));
            $from = $task->status->notation;
            $task->update(['status_id' => $this->status('cancelled'), 'decision_comment' => $comment]);
            $this->log($task, 'cancelled', $from, 'cancelled', $user, $comment, $correlation);
        }, $user);
    }

    public function note(WorkflowTask $task, string $text, User $user, ?string $correlation = null): void
    {
        ($text = trim($text)) !== '' || throw new InvalidArgumentException(__('write the note'));
        $this->mayWork($task, $user) || throw new InvalidArgumentException(__('this task is not yours to change'));
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $this->log($task, 'note-added', null, null, $user, $text, $correlation)));
    }

    /** PSIS's changePriority. */
    public function prioritise(WorkflowTask $task, string $priority, User $user, ?string $correlation = null): void
    {
        $concept = $this->term('workflow-priority', $priority);
        $this->mayWork($task, $user) || throw new InvalidArgumentException(__('this task is not yours to change'));
        if ($concept->id !== $task->priority_id) {
            $from = $task->priority->label(['en']);
            DB::transaction(fn () => AuditLog::actingAs($user, function () use ($task, $concept, $user, $from, $correlation) {
                $task->update(['priority_id' => $concept->id]);
                $this->log($task, 'priority-changed', null, null, $user, __(':from to :to', ['from' => $from, 'to' => $concept->label(['en'])]), $correlation);
            }));
        }
    }

    /** PSIS's changeDueDate, with the reason. */
    public function setDueDate(WorkflowTask $task, ?string $day, User $user, ?string $reason = null): void
    {
        $day = $this->day($day);
        $this->mayWork($task, $user) || throw new InvalidArgumentException(__('this task is not yours to change'));
        DB::transaction(fn () => AuditLog::actingAs($user, function () use ($task, $day, $user, $reason) {
            $from = $task->due_date?->toDateString() ?? __('none');
            $task->update(['due_date' => $day]);
            $this->log($task, 'due-date-changed', null, null, $user, trim(__(':from to :to', ['from' => $from, 'to' => $day ?? __('none')]).'. '.trim((string) $reason)));
        }));
    }

    /**
     * Heratio's WorkflowBulkService: the same operation on each task, one bulk operation in the history. Params:
     * assignee (a key) for assign, priority for priority, note for note, comment for approve, reject and cancel. A task
     * it cannot be done to is counted, with why.
     *
     * @param  iterable<WorkflowTask>  $tasks
     * @return array{done: int, failed: array<string, string>}
     */
    public function bulk(iterable $tasks, string $operation, array $params, User $actor): array
    {
        in_array($operation, self::BULK, true) || throw new InvalidArgumentException(__('that is not a bulk operation'));
        $assignee = $operation === 'assign' ? (User::where('institution_id', $actor->institution_id)->find($params['assignee'] ?? null)
            ?? throw new InvalidArgumentException(__('choose whom to assign them to'))) : null;
        $queue = $operation === 'queue' && ($params['queue'] ?? '') !== '' ? (WorkflowQueue::where('institution_id', $actor->institution_id)->find($params['queue'])
            ?? throw new InvalidArgumentException(__('choose the queue'))) : null;
        $correlation = (string) Str::uuid7();
        $done = 0;
        $failed = [];
        foreach ($tasks as $task) {
            try {
                $task->institution_id === $actor->institution_id || throw new InvalidArgumentException(__('not found'));
                match ($operation) {
                    'assign' => $this->assign($task, $assignee, $actor, $params['comment'] ?? null, $correlation),
                    'priority' => $this->prioritise($task, (string) ($params['priority'] ?? ''), $actor, $correlation),
                    'queue' => app(WorkflowSlaService::class)->moveToQueue($task, $queue, $actor, $correlation),
                    'note' => $this->note($task, (string) (($params['note'] ?? '') ?: ($params['comment'] ?? '')), $actor, $correlation),
                    'approve' => $this->decide($task, 'approved', $actor, $params['comment'] ?? null, $task->step->checklist ?? [], $correlation),
                    'reject' => $this->decide($task, 'rejected', $actor, $params['comment'] ?? null, [], $correlation),
                    'cancel' => $this->cancel($task, $actor, $params['comment'] ?? null, $correlation),
                };
                $done++;
            } catch (InvalidArgumentException $e) {
                $failed[$task->id] = $e->getMessage();
            }
        }

        return ['done' => $done, 'failed' => $failed];
    }

    // Lists

    /** Heratio's getStats: my open tasks, the pool, what I completed today, my overdue tasks. */
    public function stats(User $user): array
    {
        $mine = fn () => $this->open()->where('assigned_to_id', $user->id);

        return ['mine' => $mine()->count(), 'pool' => $this->pool($user)->count(),
            'completed_today' => WorkflowTask::where('decision_by_id', $user->id)->whereDate('decision_at', now()->toDateString())->count(),
            'overdue' => $mine()->whereNotNull('due_date')->where('due_date', '<', now()->toDateString())->count()];
    }

    /** The account's tasks, open ones unless a status is given; the most urgent and soonest due first. */
    public function mine(User $user, ?string $status = null): Collection
    {
        $query = WorkflowTask::where(['institution_id' => $user->institution_id, 'assigned_to_id' => $user->id]);
        $status ? $query->whereHas('status', fn ($q) => $q->where('notation', $status)) : $query->whereIn('status_id', $this->statuses(WorkflowTask::OPEN));

        return $this->ordered($query)->get();
    }

    /** Heratio's getPoolTasks: unassigned tasks of pool steps the account may take, on subjects it may change. */
    public function pool(User $user): Collection
    {
        return $this->ordered(WorkflowTask::where('institution_id', $user->institution_id)->whereNull('assigned_to_id')->where('status_id', $this->status('pending'))
            ->whereHas('step', fn ($q) => $q->where('pool_enabled', true)))->get()->filter(fn (WorkflowTask $t) => $this->eligible($t, $user))->values();
    }

    /** PSIS's getTeamWork: the open tasks of the members of a role, or of the whole institution. */
    public function team(string $institutionId, ?Role $role = null): Collection
    {
        $query = $this->open()->where('institution_id', $institutionId);
        if ($role) {
            $query->whereIn('assigned_to_id', $role->members()->pluck('users.id'));
        }

        return $this->ordered($query)->get();
    }

    /**
     * What is left of the work on a description and everything inside it: the open tasks by status and by who has
     * them, and how many units have finished every workflow started for them.
     *
     * @return array{units: int, open: int, done: int, by_status: array<string, int>, by_assignee: array<string, int>}
     */
    public function progress(RecordResource $record): array
    {
        $ids = [$record->id, ...$record->descendantIds()];
        $tasks = WorkflowTask::where('subject_table', 'record_resources')->whereIn('subject_id', $ids)->with('status.labels', 'assignedTo')->get();
        $open = $tasks->filter(fn (WorkflowTask $t) => $t->isOpen());
        $languages = Vocab::languages();

        return ['units' => count($ids), 'open' => $open->count(), 'done' => $tasks->pluck('subject_id')->unique()->diff($open->pluck('subject_id'))->count(),
            'by_status' => $open->groupBy(fn ($t) => $t->status->label($languages))->map->count()->all(),
            'by_assignee' => $open->groupBy(fn ($t) => $t->assignedTo?->name ?? __('Nobody (in the pool)'))->map->count()->sortKeys()->all()];
    }

    /** The history of a subject, the latest first. */
    public function historyOf(Model $subject): Collection
    {
        return WorkflowHistory::where(['subject_table' => $subject->getTable(), 'subject_id' => $subject->getKey()])
            ->with('action.labels', 'performedBy', 'step', 'workflow', 'toStatus.labels')->orderByDesc('performed_at')->orderByDesc('id')->get();
    }

    // Checks

    /** May the account take the task: it may change the subject and the step allows it? */
    public function eligible(WorkflowTask $task, User $user): bool
    {
        $subject = $task->subject();

        return $subject !== null && $user->institution_id === $task->institution_id && ! $user->isDisabled()
            && $user->may('update', EntityKinds::get(EntityKinds::of($subject))['permission'], $subject) && $task->step->allows($user);
    }

    /** May the account work on the task: it has it, it submitted it, or it manages workflows? */
    public function mayWork(WorkflowTask $task, User $user): bool
    {
        return $user->institution_id === $task->institution_id && ($task->assigned_to_id === $user->id || $task->submitted_by_id === $user->id || $this->manages($user));
    }

    public function manages(User $user): bool
    {
        return $user->hasPermission(Vocab::permission('manage-workflows'));
    }

    /** A subject's title, as task lists show it. */
    public function title(Model $subject): string
    {
        $kind = EntityKinds::of($subject);
        $languages = [app()->getLocale(), 'en'];

        return (string) match ($kind) {
            'record' => $subject->described('title', $languages),
            'agent' => $subject->described('agent-authorised-name', $languages),
            'repository' => $subject->described('authorised-name', $languages),
            'accession' => $subject->heading($languages),
            default => $subject->getKey(),
        };
    }

    /** The open tasks. */
    public function open(): Builder
    {
        return WorkflowTask::whereIn('status_id', $this->openStatuses());
    }

    /** @return list<string> the keys of the statuses of an open task */
    public function openStatuses(): array
    {
        return $this->statuses(WorkflowTask::OPEN);
    }

    private function ordered(Builder $query): Builder
    {
        $rank = collect(['urgent', 'high', 'normal', 'low'])->map(fn ($n, $i) => "WHEN '".$this->term('workflow-priority', $n)->id."' THEN $i")->join(' ');

        return $query->with('workflow', 'step', 'status.labels', 'priority.labels', 'assignedTo', 'submittedBy')
            ->orderByRaw("CASE priority_id::text $rank ELSE 9 END")->orderByRaw('due_date ASC NULLS LAST')->orderBy('created_at');
    }

    /** Change the task under a row lock, so two people never take or decide it at once. */
    private function locked(WorkflowTask $task, callable $change, User $actor): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($task, $change) {
            $fresh = WorkflowTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            $change($fresh);
            $task->setRawAttributes($fresh->getAttributes(), true);
            $task->setRelations([]);
        }));
    }

    private function log(WorkflowTask $task, string $action, ?string $from, ?string $to, ?User $actor, ?string $comment = null, ?string $correlation = null): WorkflowHistory
    {
        return WorkflowHistory::create(['institution_id' => $task->institution_id, 'task_id' => $task->id, 'workflow_id' => $task->workflow_id, 'step_id' => $task->step_id,
            'subject_table' => $task->subject_table, 'subject_id' => $task->subject_id, 'action_id' => $this->term('workflow-history-action', $action)->id,
            'from_status_id' => $from === null ? null : $this->status($from), 'to_status_id' => $to === null ? null : $this->status($to),
            'performed_by_id' => $actor?->id, 'performed_at' => now(), 'comment' => $comment, 'correlation_id' => $correlation]);
    }

    /** Mail an account of the institution, when the workflow sends notifications. */
    private function notify(WorkflowTask $task, string $event, ?User $to, ?string $comment = null): void
    {
        if ($to !== null && $task->workflow->notification_enabled && $to->institution_id === $task->institution_id) {
            $this->outbox->send($to, new WorkflowTaskMail($to, $task->fresh(['workflow', 'step']), $event, $comment));
        }
    }

    private function day(?string $day): ?string
    {
        if (($day = trim((string) $day)) === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);

        return $date && $date->format('Y-m-d') === $day ? $day : throw new InvalidArgumentException(__('give the date as YYYY-MM-DD'));
    }

    private function status(string $notation): string
    {
        return $this->term('workflow-task-status', $notation)->id;
    }

    private function decision(string $notation): string
    {
        return $this->term('workflow-decision', $notation)->id;
    }

    /** @param  list<string>  $notations */
    private function statuses(array $notations): array
    {
        return array_map(fn ($n) => $this->status($n), $notations);
    }

    private function term(string $vocabulary, string $notation): Concept
    {
        return $this->terms["$vocabulary/$notation"] ??= Concept::choices(Vocab::BASE.$vocabulary.'/')->where('notation', $notation)->first()
            ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
