{{-- OpenRiC Workspace - overdue workflow tasks, by queue or account, and their CSV (Heratio's overdue page, PSIS's export). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Overdue tasks'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($titles = app(\App\Description\WorkflowService::class))
    <h1>{{ __('Overdue tasks') }}</h1>
    @include('workflow._nav')
    <form method="get" style="display: flex; gap: .5rem; flex-wrap: wrap; align-items: end">
        <div><label for="queue" style="margin-top: 0">{{ __('Queue') }}</label><select id="queue" name="queue" style="font: inherit; padding: .5rem"><option value="">{{ __('Every queue') }}</option>@foreach($queues as $q)<option value="{{ $q->id }}" @selected($queue === $q->id)>{{ $q->name }}</option>@endforeach</select></div>
        <div><label for="user" style="margin-top: 0">{{ __('Assigned to') }}</label><select id="user" name="user" style="font: inherit; padding: .5rem"><option value="">{{ __('Everyone') }}</option>@foreach($users as $u)<option value="{{ $u->id }}" @selected($user === $u->id)>{{ $u->name }}</option>@endforeach</select></div>
        <button type="submit" style="margin-top: 0">{{ __('Show') }}</button>
        <a href="{{ route('workflow.overdue', ['queue' => $queue, 'user' => $user, 'format' => 'csv']) }}">{{ __('Export CSV') }}</a>
    </form>
    @if($overdue->isEmpty())
        <p>{{ __('No task is overdue.') }}</p>
    @else
        <table style="border-collapse: collapse; width: 100%">
            <thead><tr>@foreach(['Task', 'For', 'Queue', 'Assigned to', 'Due', 'Days overdue', 'Standing'] as $h)<th scope="col" style="text-align: left; padding-right: .8rem">{{ __($h) }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach($overdue as [$task, $sla])
                @php($subject = $task->subject())
                <tr><td style="padding-right: .8rem"><a href="{{ route('workflow.tasks.show', $task) }}">{{ $task->step->name }}</a></td><td style="padding-right: .8rem">{{ $subject ? $titles->title($subject) : '' }}</td>
                    <td style="padding-right: .8rem">{{ $task->queue?->name }}</td><td style="padding-right: .8rem">{{ $task->assignedTo?->name ?? __('nobody') }}</td>
                    <td style="padding-right: .8rem">{{ $sla['due_at']->toDateString() }}</td><td style="padding-right: .8rem">{{ max(0, -$sla['days_until_due']) }}</td><td>{{ $sla['label'] }}</td></tr>
            @endforeach
            </tbody>
        </table>
    @endif
@endsection
