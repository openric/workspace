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
} }}</p>
@if($task->due_date && $event === 'assigned')<p>{{ __('Due date') }}: {{ $task->due_date->format('Y-m-d') }}</p>@endif
@if($comment)<p style="white-space: pre-line">{{ $comment }}</p>@endif
<p><a href="{{ route('workflow.tasks.show', $task) }}">{{ __('Open the task') }}</a></p>
<p>{{ __('Regards,') }}<br>{{ $recipient->institution->name }}</p>
