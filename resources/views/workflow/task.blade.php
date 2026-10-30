{{-- OpenRiC Workspace - one workflow task: what it is for, its step's instructions and checklist, what may be done to it, and its history (Heratio's view task). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $task->step->name)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($status = $task->status->notation)
    @php($mine = $task->assigned_to_id === auth()->id())
    @include('workflow._nav')
    <h1>{{ $task->step->name }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <p><strong>{{ __('For') }}:</strong> @if($subject){{ $title }} (<a href="{{ route(\App\Relations\EntityKinds::get(\App\Relations\EntityKinds::of($subject))['route'], $subject) }}">{{ __('open') }}</a>)@else{{ __('no longer there') }}@endif
        <br><strong>{{ __('Workflow') }}:</strong> {{ $task->workflow->name }}, {{ __('step :n', ['n' => $task->step->position]) }} ({{ $task->step->stepType->label($lang) }}; {{ $task->step->action->label($lang) }})
        <br><strong>{{ __('Status') }}:</strong> {{ $task->status->label($lang) }} · <strong>{{ __('Priority') }}:</strong> {{ $task->priority->label($lang) }}
        · <strong>{{ __('Due date') }}:</strong> {{ $task->due_date?->format('Y-m-d') ?? __('none') }}@if($task->isOverdue()) <strong>({{ __('overdue') }})</strong>@endif
        <br><strong>{{ __('Assigned to') }}:</strong> {{ $task->assignedTo?->name ?? __('nobody') }} · <strong>{{ __('Submitted by') }}:</strong> {{ $task->submittedBy?->name ?? __('unknown') }}
        @if($task->decision->notation !== 'pending')<br><strong>{{ __('Decision') }}:</strong> {{ $task->decision->label($lang) }}, {{ $task->decisionBy?->name }} {{ $task->decision_at?->setTimezone(config('workspace.timezone'))->format('Y-m-d H:i') }}@if($task->decision_comment): {{ $task->decision_comment }}@endif @endif
        @if($task->retry_count > 0)<br><strong>{{ __('Returned') }}:</strong> {{ trans_choice(':count time|:count times', $task->retry_count, ['count' => $task->retry_count]) }}@endif</p>
    @if($task->workflow->description)<p class="meta">{{ $task->workflow->description }}</p>@endif
    @if($task->step->instructions)<h2>{{ __('Instructions') }}</h2><p style="white-space: pre-line">{{ $task->step->instructions }}</p>@endif

    <div style="display: flex; gap: .75rem; flex-wrap: wrap; align-items: end">
        @if($status === 'pending' && $task->assigned_to_id === null && $task->step->pool_enabled && $eligible)
            <form method="post" action="{{ route('workflow.tasks.act', [$task, 'claim']) }}">@csrf<button type="submit">{{ __('Claim') }}</button></form>
        @endif
        @if($mine && $status === 'claimed')
            <form method="post" action="{{ route('workflow.tasks.act', [$task, 'begin']) }}">@csrf<button type="submit">{{ __('Begin work') }}</button></form>
        @endif
        @if(in_array($status, ['claimed', 'in-progress'], true) && ($mine || $manages))
            <form method="post" action="{{ route('workflow.tasks.act', [$task, 'release']) }}">@csrf<button type="submit">{{ __('Release to the pool') }}</button></form>
        @endif
        @if($status === 'returned' && $task->submitted_by_id === auth()->id())
            <form method="post" action="{{ route('workflow.tasks.act', [$task, 'resubmit']) }}" style="display: flex; gap: .4rem; align-items: end">@csrf
                <div><label for="rs-comment" style="margin-top: 0">{{ __('What was revised') }}</label><input id="rs-comment" name="comment" maxlength="5000"></div>
                <button type="submit" style="margin-top: 0">{{ __('Resubmit') }}</button></form>
        @endif
        @if($task->isOpen() && $manages)
            <form method="post" action="{{ route('workflow.tasks.act', [$task, 'cancel']) }}">@csrf<button type="submit">{{ __('Cancel the task') }}</button></form>
        @endif
    </div>

    @if($mine && in_array($status, ['claimed', 'in-progress'], true))
        <section id="decide">
            <h2>{{ __('Decide') }}</h2>
            <form method="post" action="{{ route('workflow.tasks.act', [$task, 'decide']) }}">
                @csrf
                @if($task->step->checklist)
                    <fieldset style="border: 1px solid var(--line); padding: 0 1rem 1rem"><legend>{{ __('Checklist') }}</legend>
                        @foreach($task->step->checklist as $i => $item)
                            <label style="font-weight: normal"><input type="checkbox" name="checklist[]" value="{{ $item }}" style="width: auto"> {{ $item }}</label>
                        @endforeach
                    </fieldset>
                @endif
                <label for="d-comment">{{ __('Comment (needed to reject or return)') }}</label><textarea id="d-comment" name="comment" rows="3" style="width: 100%; font: inherit"></textarea>
                <div style="display: flex; gap: .5rem; flex-wrap: wrap">
                    <button type="submit" name="decision" value="approved">{{ $task->step->action->notation === 'approve-reject' ? __('Approve') : __('Done') }}</button>
                    @if($task->step->action->notation === 'approve-reject')
                        <button type="submit" name="decision" value="returned">{{ __('Return for revision') }}</button>
                        <button type="submit" name="decision" value="rejected">{{ __('Reject') }}</button>
                    @endif
                </div>
            </form>
        </section>
    @endif

    @if($mayWork && $task->isOpen())
        <section id="manage">
            <h2>{{ __('Assign, prioritise and note') }}</h2>
            @if($status !== 'returned')
                <form method="post" action="{{ route('workflow.tasks.act', [$task, 'assign']) }}" style="display: flex; gap: .5rem; flex-wrap: wrap; align-items: end">@csrf
                    <div><label for="a-assignee" style="margin-top: 0">{{ __('Assign to') }}</label>
                        <select id="a-assignee" name="assignee" required style="font: inherit; padding: .5rem">@foreach($assignees as $a)<option value="{{ $a->id }}" @selected($task->assigned_to_id === $a->id)>{{ $a->name }}</option>@endforeach</select></div>
                    <div style="flex: 1; min-width: 12rem"><label for="a-comment" style="margin-top: 0">{{ __('Why') }}</label><input id="a-comment" name="comment" maxlength="5000"></div>
                    <button type="submit" style="margin-top: 0">{{ __('Assign') }}</button></form>
            @endif
            <form method="post" action="{{ route('workflow.tasks.act', [$task, 'priority']) }}" style="display: flex; gap: .5rem; align-items: end; margin-top: .5rem">@csrf
                <div><label for="p-priority" style="margin-top: 0">{{ __('Priority') }}</label>
                    <select id="p-priority" name="priority" style="font: inherit; padding: .5rem">@foreach($priorities as $p)<option value="{{ $p->notation }}" @selected($task->priority_id === $p->id)>{{ $p->label($lang) }}</option>@endforeach</select></div>
                <button type="submit" style="margin-top: 0">{{ __('Save') }}</button></form>
            <form method="post" action="{{ route('workflow.tasks.act', [$task, 'due']) }}" style="display: flex; gap: .5rem; flex-wrap: wrap; align-items: end; margin-top: .5rem">@csrf
                <div><label for="due" style="margin-top: 0">{{ __('Due date') }}</label><input id="due" name="due_date" type="date" value="{{ $task->due_date?->format('Y-m-d') }}"></div>
                <div style="flex: 1; min-width: 12rem"><label for="due-reason" style="margin-top: 0">{{ __('Reason') }}</label><input id="due-reason" name="comment" maxlength="5000"></div>
                <button type="submit" style="margin-top: 0">{{ __('Save') }}</button></form>
            <form method="post" action="{{ route('workflow.tasks.act', [$task, 'note']) }}" style="display: flex; gap: .5rem; align-items: end; margin-top: .5rem">@csrf
                <div style="flex: 1"><label for="n-note" style="margin-top: 0">{{ __('Add a note') }}</label><input id="n-note" name="comment" required maxlength="5000"></div>
                <button type="submit" style="margin-top: 0">{{ __('Add') }}</button></form>
        </section>
    @endif

    <h2>{{ __('History') }}</h2>
    @forelse($task->history as $e)
        <p style="margin: .2rem 0"><strong>{{ $e->performed_at->setTimezone(config('workspace.timezone'))->format('Y-m-d H:i') }}</strong> {{ $e->action->label($lang) }}@if($e->fromStatus || $e->toStatus) ({{ collect([$e->fromStatus?->label($lang), $e->toStatus?->label($lang)])->filter()->join(' > ') }})@endif
            @if($e->performedBy), {{ $e->performedBy->name }}@endif @if($e->comment)- {{ $e->comment }}@endif</p>
    @empty
        <p>{{ __('None.') }}</p>
    @endforelse
@endsection
