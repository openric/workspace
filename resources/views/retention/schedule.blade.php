{{-- OpenRiC Workspace - a retention schedule and its disposal classes (roadmap phase 13b, ADR 0015), after Heratio's retention schedule page. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $schedule->reference.' v'.$schedule->version)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <p><a href="{{ route('retention.index') }}">{{ __('Retention and disposal') }}</a></p>
    <h1>{{ $schedule->reference }} v{{ $schedule->version }}: {{ $schedule->title }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <p>{{ __('Status') }}: <strong>{{ $schedule->status->label($lang) }}</strong>@if($schedule->approved_at) · {{ __('approved by :name on :date', ['name' => $schedule->approved_by, 'date' => $schedule->approved_at->format('Y-m-d')]) }}@endif
        @if($schedule->authority) · {{ __('Authority') }}: {{ $schedule->authority }}@endif @if($schedule->previous) · {{ __('replaces') }} <a href="{{ route('retention.schedule', $schedule->previous) }}">v{{ $schedule->previous->version }}</a>@endif</p>
    <h2>{{ __('Disposal classes') }}</h2>
    @if($schedule->classes->isEmpty())<p>{{ __('None yet.') }}</p>@else
    <table><thead><tr><th scope="col">{{ __('Reference') }}</th><th scope="col">{{ __('Title') }}</th><th scope="col">{{ __('Kept for') }}</th><th scope="col">{{ __('From') }}</th><th scope="col">{{ __('Then') }}</th><th scope="col">{{ __('Review first') }}</th></tr></thead>
        <tbody>@foreach($schedule->classes as $c)<tr><td>{{ $c->reference }}</td><td>{{ $c->title }}@unless($c->is_active) ({{ __('inactive') }})@endunless</td>
            <td>{{ collect([$c->retention_years ? trans_choice(':count year|:count years', $c->retention_years, ['count' => $c->retention_years]) : null, $c->retention_months ? trans_choice(':count month|:count months', $c->retention_months, ['count' => $c->retention_months]) : null])->filter()->join(', ') ?: '-' }}</td>
            <td>{{ $c->trigger->label($lang) }}</td><td>{{ $c->action->label($lang) }}</td><td>{{ $c->review_required ? __('Yes') : __('No') }}</td></tr>@endforeach</tbody></table>@endif
    @if($manages)
        @if($schedule->status->notation === 'draft')
            <details><summary>{{ __('Add a disposal class') }}</summary>
                <form method="post" action="{{ route('retention.classes.store', $schedule) }}">@csrf
                    <p><label for="c-ref">{{ __('Reference') }}</label> <input id="c-ref" name="reference" required maxlength="100"> <label for="c-title">{{ __('Title') }}</label> <input id="c-title" name="title" required size="40"></p>
                    <p><label for="c-y">{{ __('Years') }}</label> <input id="c-y" type="number" min="0" name="retention_years" style="width: 5rem"> <label for="c-m">{{ __('Months') }}</label> <input id="c-m" type="number" min="0" name="retention_months" style="width: 5rem">
                        <label for="c-t">{{ __('From') }}</label> <select id="c-t" name="trigger">@foreach($triggers as $t)<option value="{{ $t->notation }}">{{ $t->label($lang) }}</option>@endforeach</select>
                        <label for="c-a">{{ __('Then') }}</label> <select id="c-a" name="action">@foreach($actions as $a)<option value="{{ $a->notation }}">{{ $a->label($lang) }}</option>@endforeach</select>
                        <label><input type="hidden" name="review_required" value="0"><input type="checkbox" name="review_required" value="1" checked> {{ __('Review first') }}</label></p>
                    <p><label for="c-cit">{{ __('Citation (the clause of the authority)') }}</label> <input id="c-cit" name="citation" size="50"></p>
                    <button type="submit">{{ __('Add') }}</button></form></details>
            <details><summary>{{ __('Edit the schedule') }}</summary>
                <form method="post" action="{{ route('retention.schedules.update', $schedule) }}">@csrf @include('retention._schedule-fields', ['s' => $schedule])<button type="submit">{{ __('Save') }}</button></form></details>
            <form method="post" action="{{ route('retention.schedules.approve', $schedule) }}">@csrf <label for="ab">{{ __('Approved by (name and office)') }}</label> <input id="ab" name="approved_by" required size="40"> <button type="submit">{{ __('Approve') }}</button></form>
        @elseif($schedule->status->notation === 'approved')
            <form method="post" action="{{ route('retention.schedules.version', $schedule) }}">@csrf<button type="submit">{{ __('Start a new version') }}</button></form>
        @endif
    @endif
@endsection
