{{-- OpenRiC Workspace - the work queues with their open and late tasks, PSIS's service level overview, and the queues and service level policies for who manages workflows (Heratio's queues, PSIS's SLA). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Queues'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Queues') }}</h1>
    @include('workflow._nav')
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <p><strong>{{ __('Open tasks by standing') }}:</strong>
        @foreach(\App\Description\WorkflowSlaService::STATUSES as $s => $label)@if(isset($overview['by_status'][$s])) {{ __($label) }}: {{ $overview['by_status'][$s] }} ·@endif @endforeach
        <a href="{{ route('workflow.overdue') }}">{{ __('Overdue tasks') }}</a></p>
    <section id="queues">
        <table style="border-collapse: collapse; width: 100%">
            <thead><tr>@foreach(['Queue', 'Open tasks', 'Late', 'Service level (days)', 'Description'] as $h)<th scope="col" style="text-align: left; padding-right: 1rem">{{ __($h) }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach($queues as $q)
                <tr>
                    <td style="padding-right: 1rem">@if($q->color)<span aria-hidden="true" style="display: inline-block; width: .7rem; height: .7rem; border-radius: 50%; background: {{ $q->color }}"></span> @endif<a href="{{ route('workflow.overdue', ['queue' => $q->id]) }}">{{ $q->name }}</a>@unless($q->is_active) ({{ __('inactive') }})@endunless</td>
                    <td style="padding-right: 1rem">{{ $counts[$q->id]['open'] }}</td>
                    <td style="padding-right: 1rem">{{ $counts[$q->id]['late'] }}</td>
                    <td style="padding-right: 1rem">{{ $q->sla_days }}</td>
                    <td>{{ $q->description }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @if($manages)
            @foreach($queues as $q)
                <details><summary>{{ __('Edit :name', ['name' => $q->name]) }}</summary>
                    <form method="post" action="{{ route('workflow.queues.save', $q) }}">@csrf @include('workflow._queue-fields', ['q' => $q, 'p' => 'q'.$loop->index])<button type="submit">{{ __('Save') }}</button></form></details>
            @endforeach
            <details><summary>{{ __('Add a queue') }}</summary>
                <form method="post" action="{{ route('workflow.queues.save') }}">@csrf @include('workflow._queue-fields', ['q' => null, 'p' => 'q-new'])<button type="submit">{{ __('Add') }}</button></form></details>
        @endif
    </section>
    <h2>{{ __('By account') }}</h2>
    @foreach($overview['by_user'] as $who => $n)<p style="margin: .1rem 0">{{ $who }}: {{ trans_choice(':count open|:count open', $n['open'], ['count' => $n['open']]) }}, {{ trans_choice(':count late|:count late', $n['late'], ['count' => $n['late']]) }}</p>@endforeach

    @if($manages)
        <section id="policies">
            <h2>{{ __('Service level policies') }}</h2>
            <p class="meta">{{ __('A task is at risk the warning days before it is due, and escalated the escalation days after it was made. Its queue\'s policy applies, else its workflow\'s, else the default; an urgent task has 40% of the days, a high one 60%, a low one 150%.') }}</p>
            @foreach($policies as $p)
                <details><summary>{{ $p->name }}: {{ __('due in :due, warning :warning before, escalation after :escalation days, :action', ['due' => $p->due_days, 'warning' => $p->warning_days, 'escalation' => $p->escalation_days, 'action' => mb_strtolower($p->escalationAction->label($lang))]) }}@unless($p->is_active) ({{ __('inactive') }})@endunless</summary>
                    <form method="post" action="{{ route('workflow.policies.save', $p) }}">@csrf @include('workflow._policy-fields', ['pol' => $p, 'p' => 'pol'.$loop->index])<button type="submit">{{ __('Save') }}</button></form></details>
            @endforeach
            <details><summary>{{ __('Add a policy') }}</summary>
                <form method="post" action="{{ route('workflow.policies.save') }}">@csrf @include('workflow._policy-fields', ['pol' => null, 'p' => 'pol-new'])<button type="submit">{{ __('Add') }}</button></form></details>
        </section>
    @endif
@endsection
