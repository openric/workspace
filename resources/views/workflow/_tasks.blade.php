{{-- OpenRiC Workspace - a list of workflow tasks, chosen for a bulk operation when $bulk. Needs $tasks; $bulk, $priorities, $accounts for the bulk form. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($lang = \App\Support\Vocab::languages())
@php($titles = app(\App\Description\WorkflowService::class))
@php($sla = app(\App\Description\WorkflowSlaService::class))
@if($tasks->isEmpty())
    <p>{{ __('No tasks.') }}</p>
@else
    @if($bulk ?? false)<form method="post" action="{{ route('workflow.bulk') }}">@csrf @endif
    <table style="border-collapse: collapse; width: 100%">
        <thead><tr>@if($bulk ?? false)<th scope="col"><span class="visually-hidden">{{ __('Choose') }}</span></th>@endif
            @foreach(['Task', 'For', 'Workflow', 'Status', 'Priority', 'Assigned to', 'Due date', 'Service level'] as $h)<th scope="col" style="text-align: left; padding-right: .8rem">{{ __($h) }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach($tasks as $task)
            @php($subject = $task->subject())
            <tr>
                @if($bulk ?? false)<td><input type="checkbox" name="tasks[]" value="{{ $task->id }}" aria-label="{{ __('Choose :step', ['step' => $task->step->name]) }}" style="width: auto"></td>@endif
                <td style="padding-right: .8rem"><a href="{{ route('workflow.tasks.show', $task) }}">{{ $task->step->name }}</a></td>
                <td style="padding-right: .8rem">{{ $subject ? $titles->title($subject) : '' }}</td>
                <td style="padding-right: .8rem">{{ $task->workflow->name }}</td>
                <td style="padding-right: .8rem">{{ $task->status->label($lang) }}</td>
                <td style="padding-right: .8rem">{{ $task->priority->label($lang) }}</td>
                <td style="padding-right: .8rem">{{ $task->assignedTo?->name ?? __('nobody') }}</td>
                <td style="padding-right: .8rem">@if($task->due_date){{ $task->due_date->format('Y-m-d') }}@if($task->isOverdue()) <strong>({{ __('overdue') }})</strong>@endif @endif</td>
                <td style="padding-right: .8rem">{{ $task->isOpen() ? $sla->compute($task)['label'] : '' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @if($bulk ?? false)
        <fieldset style="margin-top: 1rem; border: 1px solid var(--line); padding: 0 1rem 1rem">
            <legend>{{ __('With those chosen') }}</legend>
            <div style="display: flex; gap: .6rem; flex-wrap: wrap; align-items: end">
                <div><label for="b-op" style="margin-top: 0">{{ __('Do') }}</label>
                    <select id="b-op" name="operation" style="font: inherit; padding: .5rem">
                        @foreach(['assign' => 'Assign to', 'priority' => 'Set priority', 'queue' => 'Move to the queue', 'note' => 'Add a note', 'approve' => 'Approve', 'reject' => 'Reject', 'cancel' => 'Cancel'] as $op => $text)<option value="{{ $op }}">{{ __($text) }}</option>@endforeach
                    </select></div>
                <div><label for="b-assignee" style="margin-top: 0">{{ __('Assignee') }}</label>
                    <select id="b-assignee" name="assignee" style="font: inherit; padding: .5rem"><option value="">-</option>@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></div>
                <div><label for="b-priority" style="margin-top: 0">{{ __('Priority') }}</label>
                    <select id="b-priority" name="priority" style="font: inherit; padding: .5rem"><option value="">-</option>@foreach($priorities as $p)<option value="{{ $p->notation }}">{{ $p->label($lang) }}</option>@endforeach</select></div>
                <div><label for="b-queue" style="margin-top: 0">{{ __('Queue') }}</label>
                    <select id="b-queue" name="queue" style="font: inherit; padding: .5rem"><option value="">-</option>@foreach($sla->queues(auth()->user()->institution_id, true) as $q)<option value="{{ $q->id }}">{{ $q->name }}</option>@endforeach</select></div>
                <div style="flex: 1; min-width: 12rem"><label for="b-note" style="margin-top: 0">{{ __('Note or comment') }}</label><input id="b-note" name="comment" maxlength="5000"></div>
                <button type="submit" style="margin-top: 0">{{ __('Apply') }}</button>
            </div>
        </fieldset>
    </form>
    @endif
@endif
