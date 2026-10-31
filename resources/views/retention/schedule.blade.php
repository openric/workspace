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
        <tbody>@foreach($schedule->classes as $c)<tr><td>{{ $c->reference }}</td><td>{{ $c->title }}@unless($c->is_active) ({{ __('inactive') }})@endunless @if($c->description)<br><small>{{ $c->description }}</small>@endif</td>
            <td>{{ collect([$c->retention_years ? trans_choice(':count year|:count years', $c->retention_years, ['count' => $c->retention_years]) : null, $c->retention_months ? trans_choice(':count month|:count months', $c->retention_months, ['count' => $c->retention_months]) : null])->filter()->join(', ') ?: '-' }}</td>
            <td>{{ $c->trigger->label($lang) }}</td><td>{{ $c->action->label($lang) }}</td><td>{{ $c->review_required ? __('Yes') : __('No') }}</td></tr>@endforeach</tbody></table>@endif
    @if($manages)
        @if($schedule->status->notation === 'draft')
            <details><summary>{{ __('Add a disposal class') }}</summary>
                <form method="post" action="{{ route('retention.classes.store', $schedule) }}">@csrf @include('retention._class-fields', ['c' => null, 'p' => 'class-new'])
                    <button type="submit">{{ __('Add') }}</button></form></details>
            @foreach($schedule->classes as $c)
                <details><summary>{{ __('Edit :class', ['class' => $c->reference]) }}</summary>
                    <form method="post" action="{{ route('retention.classes.update', [$schedule, $c]) }}">@csrf @include('retention._class-fields', ['c' => $c, 'p' => 'class'.$loop->index])
                        <button type="submit">{{ __('Save') }}</button></form></details>
            @endforeach
            <details><summary>{{ __('Edit the schedule') }}</summary>
                <form method="post" action="{{ route('retention.schedules.update', $schedule) }}">@csrf @include('retention._schedule-fields', ['s' => $schedule])<button type="submit">{{ __('Save') }}</button></form></details>
            <form method="post" action="{{ route('retention.schedules.approve', $schedule) }}">@csrf <label for="ab">{{ __('Approved by (name and office)') }}</label> @include('description._ric', ['term' => null]) <input id="ab" name="approved_by" required size="40"> <button type="submit">{{ __('Approve') }}</button></form>
        @elseif($schedule->status->notation === 'approved')
            <form method="post" action="{{ route('retention.schedules.version', $schedule) }}">@csrf<button type="submit">{{ __('Start a new version') }}</button></form>
        @endif
    @endif
@endsection
