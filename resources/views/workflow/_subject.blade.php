{{-- OpenRiC Workspace - an entity's workflow: its open tasks, starting a workflow (for a description, with everything inside it), what is left inside it and its history (Heratio's workflow on the description, PSIS's start workflow and object history). Needs $subject, $kind (records, agents, repositories or accessions), $canUpdate. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($service = app(\App\Description\WorkflowService::class))
@php($lang = \App\Support\Vocab::languages())
@php($open = $service->open()->where(['subject_table' => $subject->getTable(), 'subject_id' => $subject->getKey()])->with('step', 'workflow', 'status.labels', 'assignedTo')->get())
@php($history = $service->historyOf($subject)->take(10))
<section id="workflow">
    <h2>{{ __('Workflow') }}</h2>
    @foreach($open as $task)
        <p style="margin: .2rem 0"><a href="{{ route('workflow.tasks.show', $task) }}">{{ $task->step->name }}</a> ({{ $task->workflow->name }}): {{ $task->status->label($lang) }}, {{ $task->assignedTo?->name ?? __('in the pool') }}@if($task->due_date), {{ __('due :date', ['date' => $task->due_date->format('Y-m-d')]) }}@endif</p>
    @endforeach
    @if($subject instanceof \App\Models\RecordResource && $subject->children()->exists())
        @php($progress = $service->progress($subject))
        @if($progress['open'] > 0 || $progress['done'] > 0)
            <p><strong>{{ __('Inside it') }}:</strong> {{ __(':open tasks open; :done of :units units through their workflow.', ['open' => $progress['open'], 'done' => $progress['done'], 'units' => $progress['units']]) }}
                @foreach($progress['by_assignee'] as $who => $n)<br>{{ $who }}: {{ $n }}@endforeach</p>
        @endif
    @endif
    @if($open->isEmpty() && $history->isEmpty())<p>{{ __('Not in a workflow.') }}</p>@endif
    @if($canUpdate)
        @php($workflows = \App\Models\Workflow::where(['institution_id' => $subject->getAttribute('institution_id'), 'applies_to' => \App\Relations\EntityKinds::of($subject), 'is_active' => true])->orderBy('name')->get())
        @if($workflows->isNotEmpty())
            <details>
                <summary>{{ __('Start a workflow') }}</summary>
                <form method="post" action="{{ route('workflow.start', [$kind, $subject->getKey()]) }}">
                    @csrf
                    @php($applicable = $service->applicable($subject))
                    <label for="wf-workflow">{{ __('Workflow') }}</label>
                    <select id="wf-workflow" name="workflow" style="font: inherit; padding: .5rem">@foreach($workflows as $w)<option value="{{ $w->id }}" @selected($applicable?->id === $w->id)>{{ $w->name }}</option>@endforeach</select>
                    <div style="display: flex; gap: .75rem; flex-wrap: wrap">
                        <div><label for="wf-priority">{{ __('Priority') }}</label><select id="wf-priority" name="priority" style="font: inherit; padding: .5rem">@foreach(\App\Models\Concept::choices(\App\Support\Vocab::BASE.'workflow-priority/')->with('labels')->get() as $p)<option value="{{ $p->notation }}" @selected($p->notation === 'normal')>{{ $p->label($lang) }}</option>@endforeach</select></div>
                        <div><label for="wf-due">{{ __('Due date') }}</label><input id="wf-due" name="due_date" type="date"></div>
                    </div>
                    @if($subject instanceof \App\Models\RecordResource)
                        <input type="hidden" name="inside" value="0">
                        <label style="font-weight: normal"><input type="checkbox" name="inside" value="1" style="width: auto"> {{ __('And everything inside it, to divide the work') }}</label>
                    @endif
                    <button type="submit">{{ __('Start') }}</button>
                </form>
            </details>
        @endif
    @endif
    @if($history->isNotEmpty())
        <details><summary>{{ __('Workflow history') }}</summary>@include('workflow._events', ['events' => $history])</details>
    @endif
</section>
