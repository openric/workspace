{{-- OpenRiC Workspace - WorkflowTaskMail body. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($subject = $task->subject())
@php($title = $subject ? app(\App\Description\WorkflowService::class)->title($subject) : '')
<p>{{ __('Dear :name,', ['name' => $recipient->name]) }}</p>
<p>{{ match ($event) {
    'assigned' => __('The step :step of the workflow :workflow, for :title, is yours.', ['step' => $task->step->name, 'workflow' => $task->workflow->name, 'title' => $title]),
    'approved' => __('The step :step of the workflow :workflow, for :title, was approved.', ['step' => $task->step->name, 'workflow' => $task->workflow->name, 'title' => $title]),
    'rejected' => __('The step :step of the workflow :workflow, for :title, was rejected.', ['step' => $task->step->name, 'workflow' => $task->workflow->name, 'title' => $title]),
    'returned' => __('The step :step of the workflow :workflow, for :title, was returned to you for revision.', ['step' => $task->step->name, 'workflow' => $task->workflow->name, 'title' => $title]),
    'completed' => __('The workflow :workflow, for :title, is completed.', ['workflow' => $task->workflow->name, 'title' => $title]),
    'due-soon' => __('The step :step of the workflow :workflow, for :title, is due soon.', ['step' => $task->step->name, 'workflow' => $task->workflow->name, 'title' => $title]),
    'overdue' => __('The step :step of the workflow :workflow, for :title, is overdue.', ['step' => $task->step->name, 'workflow' => $task->workflow->name, 'title' => $title]),
    'escalated' => __('The step :step of the workflow :workflow, for :title, has passed its service level and is escalated to you.', ['step' => $task->step->name, 'workflow' => $task->workflow->name, 'title' => $title]),
} }}</p>
@if($task->due_date && in_array($event, ['assigned', 'due-soon', 'overdue', 'escalated'], true))<p>{{ __('Due date') }}: {{ $task->due_date->format('Y-m-d') }}</p>@endif
@if($comment)<p style="white-space: pre-line">{{ $comment }}</p>@endif
<p><a href="{{ route('workflow.tasks.show', $task) }}">{{ __('Open the task') }}</a></p>
<p>{{ __('Regards,') }}<br>{{ $recipient->institution->name }}</p>
